<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseApprovalStep;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestHistory;
use App\Entity\User\User;
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

    /**
     * Решение директора из разбора новых заявок: правки состава и отправка дальше.
     *
     * Всё одной транзакцией намеренно. Разнеси правку позиций и вердикт по двум
     * запросам — и закрытая на полпути вкладка оставит заявку с урезанным составом,
     * но без решения: у согласанта окажется не то, что директор согласовал.
     *
     * Согласанты приходят от директора списком; если он никого не отметил,
     * заявка идёт сразу в отдел закупок — шаг для них в маршруте уже есть.
     *
     * @param array<int, array{included: bool, quantity: string|null}> $itemEdits ключ — id позиции
     * @param list<User> $approvers
     */
    public function directorSend(
        PurchaseRequest $request,
        PurchaseApprovalStep $step,
        User $actor,
        array $itemEdits,
        array $approvers,
    ): void {
        $this->assertActiveStep($request, $step);

        $changes = $this->applyDirectorEdits($request, $itemEdits);

        if ($changes !== []) {
            $this->addHistory(
                $request,
                $actor,
                $request->getStatus(),
                $request->getStatus(),
                'Состав правил директор: ' . implode('; ', $changes),
            );
        }

        $this->routeBuilder->addApprovers($request, $approvers);

        // Подпись директора закрывает его позицию: указатель уедет либо на
        // согласантов, которых мы только что добавили, либо сразу в закупки.
        $this->approveStep($request, $step, $actor);
    }

    /** «Рассмотрю позже»: заявка остаётся у директора, но уходит в конец очереди. */
    public function directorDefer(PurchaseRequest $request, PurchaseApprovalStep $step): void
    {
        $this->assertActiveStep($request, $step);

        $step->setDeferredAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    /**
     * Применить правки состава: снятые галочки и изменённое количество.
     *
     * Позиции не удаляются — заявленный автором состав остаётся в строке, а
     * решение директора ложится рядом (`excluded`, `approved_quantity`).
     * Снять всё нельзя: заявка без единой позиции — это отказ, и оформлять его
     * надо отказом, иначе в закупки уедет пустой заказ.
     *
     * @param array<int, array{included: bool, quantity: string|null}> $itemEdits
     * @return list<string> человекочитаемый дифф для истории
     */
    private function applyDirectorEdits(PurchaseRequest $request, array $itemEdits): array
    {
        $changes = [];

        foreach ($request->getItems() as $item) {
            $edit = $itemEdits[(int) $item->getId()] ?? null;
            if ($edit === null) {
                continue;
            }

            if ($edit['included'] === false && !$item->isExcluded()) {
                $item->setExcluded(true);
                $changes[] = sprintf('снята позиция «%s»', (string) $item->getName());
                continue;
            }

            $item->setExcluded(false);

            $quantity = $edit['quantity'];
            if ($quantity === null || (float) $quantity === (float) $item->getQuantity()) {
                $item->setApprovedQuantity(null);
                continue;
            }
            if ((float) $quantity <= 0) {
                throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_ITEM);
            }

            $item->setApprovedQuantity($quantity);
            $changes[] = sprintf(
                'количество «%s»: %s → %s',
                (string) $item->getName(),
                rtrim(rtrim((string) $item->getQuantity(), '0'), '.'),
                rtrim(rtrim($quantity, '0'), '.'),
            );
        }

        $hasIncluded = false;
        foreach ($request->getItems() as $item) {
            if (!$item->isExcluded()) {
                $hasIncluded = true;
                break;
            }
        }
        if (!$hasIncluded) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_ITEMS_REQUIRED);
        }

        return $changes;
    }

    /**
     * Снять свою подпись — персональный откат для директора.
     *
     * Обычная подпись необратима: позиция закрылась, следующим ушли уведомления.
     * Но у директора на его позиции обычно никого больше нет, поэтому его подпись
     * закрывает позицию сразу и «окна на передумать» не остаётся вовсе — а ошибиться
     * тоггглом легко. Отзыв маршрута это лечит, но требует второго человека
     * из отдела закупок.
     *
     * Поэтому здесь то же, что делает recall, но в границах одного человека:
     * сбрасываем его шаг и всё, что успело подписаться после него, и возвращаем
     * указатель на его позицию. Сгоревшие согласования видны только в history,
     * поэтому запись туда обязательна и идёт той же транзакцией.
     */
    public function revokeStep(PurchaseRequest $request, PurchaseApprovalStep $step, User $actor): void
    {
        $statusBefore = $request->getStatus();

        // APPROVED тоже допустим: если директор был последним в цепочке, маршрут
        // уже закрыт — но пока закупки не начали исполнение, откатить можно.
        if (!in_array($statusBefore, [PurchaseStatus::ON_APPROVAL, PurchaseStatus::APPROVED], true)) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }
        if ($step->getPurchaseRequest()?->getId() !== $request->getId()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_STEP_NOT_FOUND);
        }
        // Именно СВОЮ: чужую подпись снимает только отзыв маршрута отделом закупок
        if ($step->getDecision() !== PurchaseStepDecision::APPROVED
            || $step->getDecidedBy()?->getId() !== $actor->getId()
        ) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_STEP_NOT_REVOKABLE);
        }

        $anchor = $step->getPosition();
        $burned = [];

        foreach ($request->getSteps() as $candidate) {
            if ($candidate->getPosition() < $anchor || !$candidate->getDecision()->isDecided()) {
                continue;
            }
            if ($candidate !== $step) {
                $burned[] = $this->describeStep($candidate);
            }
            $candidate->setDecision(PurchaseStepDecision::PENDING)
                ->setDecidedBy(null)
                ->setDecidedAt(null)
                ->setComment(null);
        }

        $request->setStatus(PurchaseStatus::ON_APPROVAL);

        $this->addHistory(
            $request,
            $actor,
            $statusBefore,
            PurchaseStatus::ON_APPROVAL,
            $burned === []
                ? 'Согласование снято автором подписи'
                : sprintf(
                    'Согласование снято автором подписи. Сброшены согласования после него: %s',
                    implode(', ', $burned),
                ),
        );
        $this->em->flush();

        $this->notifier->notifyStepActivated($request, $actor);
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
