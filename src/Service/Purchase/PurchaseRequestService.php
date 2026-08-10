<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseApprovalStep;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestHistory;
use App\Entity\Purchase\PurchaseRouteTemplate;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchasePriority;
use App\Enum\Purchase\PurchaseRequestKind;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\Purchase\PurchaseStepDecision;
use App\Enum\User\UserRole;
use App\Repository\Purchase\PurchaseSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Движение заявки на закупку.
 *
 * Согласование — не цепочка статусов, а шаги маршрута (PurchaseApprovalStep).
 * Здесь живёт исполнитель: указатель стоит на минимальной незакрытой позиции,
 * закрылась вся позиция — идём к следующей, шагов не осталось — заявка APPROVED.
 *
 * Права («кто может») проверяет ролевой гейт контроллера: у него есть Security.
 * Здесь — только корректность («можно ли сейчас»), запись в историю и уведомление.
 */
final class PurchaseRequestService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PurchaseNotificationPublisher $notifier,
        private readonly PurchaseSettingRepository $settings,
        private readonly ApprovalRouteBuilder $routeBuilder,
    ) {}

    /** Запись о создании заявки (from = NULL). Без flush — вызывается при создании. */
    public function logCreated(PurchaseRequest $request, User $actor): void
    {
        $this->addHistory($request, $actor, null, PurchaseStatus::DRAFT);
    }

    /**
     * DRAFT | REJECTED → ON_APPROVAL: собираем маршрут и запускаем его.
     * Повторная подача строит маршрут заново — сумма и состав могли измениться.
     */
    public function submit(PurchaseRequest $request, User $actor): void
    {
        $from = $request->getStatus();
        if (!$from->isEditable()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }
        if ($request->getItems()->isEmpty()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_ITEMS_REQUIRED);
        }

        // Потолок быстрой заявки стережём на сервере: спрятанная кнопка защитой не является.
        if ($request->getCreatedAs() === PurchaseRequestKind::FAST
            && $request->getTotalAmount() > $this->settings->getFastMaxAmount()
        ) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_FAST_LIMIT_EXCEEDED);
        }

        // У быстрой заявки короткая форма — записку не требуем.
        if ($request->getCreatedAs() !== PurchaseRequestKind::FAST
            && trim((string) $request->getJustification()) === ''
            && !$request->hasFileOfType(PurchaseFileType::JUSTIFICATION)
        ) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_JUSTIFICATION_REQUIRED);
        }

        $this->routeBuilder->build($request);

        $this->transition($request, $actor, PurchaseStatus::ON_APPROVAL);
        $this->notifier->notifySubmitted($request, $actor, resubmitted: $from === PurchaseStatus::REJECTED);
        $this->notifier->notifyStepActivated($request, $actor);
    }

    /**
     * Согласовать свой шаг. Решение необратимо: позиция закрывается и следующим
     * участникам уходят уведомления — отзывать их было бы некорректно.
     */
    public function approveStep(PurchaseRequest $request, PurchaseApprovalStep $step, User $actor, ?string $comment = null): void
    {
        $this->assertActiveStep($request, $step);

        // Требование файла живёт на шаге, а не в конвейере: у быстрого маршрута
        // шага «договор» нет, и требовать с него договор не за что.
        $requiredFile = $step->getRequiresFileType();
        if ($requiredFile !== null && !$request->hasFileOfType($requiredFile)) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_STEP_FILE_REQUIRED);
        }

        $positionBefore = $request->getCurrentPosition();

        $step->setDecision(PurchaseStepDecision::APPROVED)
            ->setDecidedBy($actor)
            ->setDecidedAt(new \DateTimeImmutable())
            ->setComment($this->normalizeComment($comment));

        $this->em->flush();

        if ($request->getCurrentPosition() === null) {
            $this->transition($request, $actor, PurchaseStatus::APPROVED);
            $this->notifier->notifyApproved($request, $actor);

            return;
        }

        // Позиция закрылась целиком — зовём следующих. Пока в позиции остались
        // незакрытые параллельные шаги, никого не дёргаем.
        if ($request->getCurrentPosition() !== $positionBefore) {
            $this->notifier->notifyStepActivated($request, $actor);
        }
    }

    /** Вернуть заявку автору со своего шага. Комментарий обязателен. */
    public function rejectStep(PurchaseRequest $request, PurchaseApprovalStep $step, User $actor, string $comment): void
    {
        $this->assertActiveStep($request, $step);

        if (trim($comment) === '') {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_COMMENT_REQUIRED);
        }

        $step->setDecision(PurchaseStepDecision::REJECTED)
            ->setDecidedBy($actor)
            ->setDecidedAt(new \DateTimeImmutable())
            ->setComment($comment);

        $this->transition($request, $actor, PurchaseStatus::REJECTED, $comment);
        $this->notifier->notifyRejected($request, $actor, $comment);
    }

    /**
     * Переложить заявку на другой шаблон маршрута (переключатель отдела закупок).
     * Можно только пока указатель на их первом шаге — дальше маршрут заморожен.
     */
    public function switchTemplate(PurchaseRequest $request, PurchaseRouteTemplate $template, User $actor): void
    {
        $this->assertRouteEditable($request);

        $request->setRouteTemplate($template);
        $this->routeBuilder->applyTemplate($request, $template);
        $this->em->flush();

        $this->addHistory(
            $request,
            $actor,
            $request->getStatus(),
            $request->getStatus(),
            sprintf('Маршрут переключён на «%s»', $template->getName()),
        );
        $this->em->flush();
    }

    /**
     * Заменить незакрытую часть маршрута присланным списком шагов.
     *
     * Уже принятые решения не трогаются вовсе — их и нельзя удалить, они аудит.
     * Обязательные шаги (директор от своего порога) должны остаться в списке:
     * отдел закупок маршрут удлиняет и переставляет, но не укорачивает ниже того,
     * что директор установил для себя.
     *
     * @param list<PurchaseApprovalStep> $newSteps несохранённые шаги, собранные контроллером
     */
    public function replaceRoute(PurchaseRequest $request, array $newSteps, User $actor): void
    {
        $this->assertRouteEditable($request);

        $before = [];
        $mandatoryKeys = [];
        foreach ($request->getSteps() as $step) {
            if ($step->getDecision()->isDecided()) {
                continue;
            }
            $before[] = $this->describeStep($step);
            if ($step->isMandatory()) {
                $mandatoryKeys[$this->stepKey($step)] = $this->describeStep($step);
            }
        }

        $newKeys = [];
        foreach ($newSteps as $step) {
            $newKeys[$this->stepKey($step)] = true;
        }
        foreach ($mandatoryKeys as $key => $label) {
            if (!isset($newKeys[$key])) {
                throw new PurchaseTransitionException(SpaApiError::PURCHASE_ROUTE_MANDATORY_STEP);
            }
        }

        foreach ($request->getSteps()->toArray() as $step) {
            if (!$step->getDecision()->isDecided()) {
                $request->removeStep($step);
                $this->em->remove($step);
            }
        }

        $after = [];
        foreach ($newSteps as $step) {
            $step->setMandatory(isset($mandatoryKeys[$this->stepKey($step)]));
            $step->setCreatedBy($actor);
            $request->addStep($step);
            $this->em->persist($step);
            $after[] = $this->describeStep($step);
        }

        $this->em->flush();

        $this->addHistory(
            $request,
            $actor,
            $request->getStatus(),
            $request->getStatus(),
            sprintf('Маршрут изменён: было [%s], стало [%s]', implode(', ', $before), implode(', ', $after)),
        );
        $this->em->flush();
    }

    /** Ключ шага для сравнения маршрутов: кого ждём, без учёта позиции. */
    private function stepKey(PurchaseApprovalStep $step): string
    {
        return $step->getApproverUser() !== null
            ? 'u:' . $step->getApproverUser()->getId()
            : 'r:' . (string) $step->getApproverRole();
    }

    /**
     * Отзыв: заявка ушла дальше, а маршрут надо переделать.
     * Все шаги после первого шага отдела закупок сбрасываются, указатель
     * возвращается к ним. Полученные согласования сгорают — люди подтверждают
     * заново; их прошлые решения после сброса видны ТОЛЬКО в history, поэтому
     * запись туда обязательна и делается здесь же.
     */
    public function recall(PurchaseRequest $request, User $actor, string $reason): void
    {
        if ($request->getStatus() !== PurchaseStatus::ON_APPROVAL) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }
        if (trim($reason) === '') {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_COMMENT_REQUIRED);
        }

        $anchor = $this->firstDepartmentPosition($request);
        $burned = [];

        foreach ($request->getSteps() as $step) {
            if ($step->getPosition() >= $anchor && $step->getDecision()->isDecided()) {
                $burned[] = sprintf(
                    '%s (%s)',
                    $this->describeStep($step),
                    $step->getDecision()->getLabel(),
                );
                $step->setDecision(PurchaseStepDecision::PENDING)
                    ->setDecidedBy(null)
                    ->setDecidedAt(null)
                    ->setComment(null);
            }
        }

        $this->addHistory(
            $request,
            $actor,
            $request->getStatus(),
            $request->getStatus(),
            sprintf(
                'Маршрут отозван: %s. Сброшены согласования: %s',
                $reason,
                $burned === [] ? 'нет' : implode(', ', $burned),
            ),
        );
        $this->em->flush();

        $this->notifier->notifyStepActivated($request, $actor);
    }

    /**
     * Шаг конвейера исполнения: строго следующий статус.
     * Договора здесь больше нет — он стал шагом маршрута, и его файл требует
     * тот шаг. Исполнителем становится тот, кто сделал первый шаг.
     */
    public function advance(PurchaseRequest $request, User $actor, PurchaseStatus $target): void
    {
        if ($request->getStatus()->nextExecutionStatus() !== $target) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }

        if ($request->getExecutor() === null) {
            $request->setExecutor($actor);
        }

        $this->transition($request, $actor, $target);

        if ($target === PurchaseStatus::DELIVERED) {
            $this->notifier->notifyDelivered($request, $actor);
        } else {
            $this->notifier->notifyStatusChanged($request, $actor);
        }
    }

    /** DELIVERED → DONE — приёмка департаментом. */
    public function confirm(PurchaseRequest $request, User $actor): void
    {
        $this->assertStatus($request, PurchaseStatus::DELIVERED);

        $this->transition($request, $actor, PurchaseStatus::DONE);
        $this->notifier->notifyConfirmed($request, $actor);
    }

    /** Отмена из любого нефинального статуса. */
    public function cancel(PurchaseRequest $request, User $actor, ?string $comment): void
    {
        if ($request->getStatus()->isFinal()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }

        $this->transition($request, $actor, PurchaseStatus::CANCELLED, $comment);
        $this->notifier->notifyCancelled($request, $actor, $comment);
    }

    /** Смена приоритета (без записи в историю переходов). */
    public function setPriority(PurchaseRequest $request, User $actor, PurchasePriority $priority): void
    {
        if ($request->getStatus()->isFinal()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }

        $request->setPriority($priority);
        $this->em->flush();
    }

    /**
     * Маршрут правится только пока указатель на первом шаге отдела закупок.
     * После их approve заявка ушла к людям, и менять цепочку под ними нельзя —
     * для этого есть recall.
     */
    public function assertRouteEditable(PurchaseRequest $request): void
    {
        if ($request->getStatus() !== PurchaseStatus::ON_APPROVAL) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }

        $current = $request->getCurrentPosition();
        if ($current === null || $current !== $this->firstDepartmentPosition($request)) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_ROUTE_LOCKED);
        }
    }

    /** Шаг существует у этой заявки, не решён и стоит на позиции указателя. */
    private function assertActiveStep(PurchaseRequest $request, PurchaseApprovalStep $step): void
    {
        if ($step->getPurchaseRequest()?->getId() !== $request->getId()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_STEP_NOT_FOUND);
        }
        if ($request->getStatus() !== PurchaseStatus::ON_APPROVAL) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }
        if (!$step->isPending() || $step->getPosition() !== $request->getCurrentPosition()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_STEP_NOT_ACTIVE);
        }
    }

    /**
     * Позиция первого шага отдела закупок. В обычном маршруте они стоят дважды
     * (рассмотрение и договор) — якорь всегда первый.
     */
    private function firstDepartmentPosition(PurchaseRequest $request): int
    {
        $min = null;
        foreach ($request->getSteps() as $step) {
            if ($step->getApproverRole() === UserRole::ROLE_PURCHASE_DEPARTMENT->value
                && ($min === null || $step->getPosition() < $min)
            ) {
                $min = $step->getPosition();
            }
        }

        return $min ?? 1;
    }

    private function describeStep(PurchaseApprovalStep $step): string
    {
        if ($step->getTitle() !== null && $step->getTitle() !== '') {
            return $step->getTitle();
        }

        $user = $step->getApproverUser();
        if ($user !== null) {
            return trim(($user->getLastname() ?? '') . ' ' . ($user->getFirstname() ?? ''));
        }

        return UserRole::tryFrom((string) $step->getApproverRole())?->getLabel()
            ?? (string) $step->getApproverRole();
    }

    private function normalizeComment(?string $comment): ?string
    {
        return $comment !== null && trim($comment) !== '' ? $comment : null;
    }

    private function assertStatus(PurchaseRequest $request, PurchaseStatus $expected): void
    {
        if ($request->getStatus() !== $expected) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }
    }

    private function transition(PurchaseRequest $request, User $actor, PurchaseStatus $to, ?string $comment = null): void
    {
        $from = $request->getStatus();
        $request->setStatus($to);
        $this->addHistory($request, $actor, $from, $to, $comment);
        $this->em->flush();
    }

    private function addHistory(PurchaseRequest $request, User $actor, ?PurchaseStatus $from, PurchaseStatus $to, ?string $comment = null): void
    {
        $entry = new PurchaseRequestHistory();
        $entry->setUser($actor);
        $entry->setFromStatus($from);
        $entry->setToStatus($to);
        $entry->setComment($this->normalizeComment($comment));
        $request->addHistory($entry);
        $this->em->persist($entry);
    }
}
