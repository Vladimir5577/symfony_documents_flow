<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseApprovalStep;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestHistory;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseHistoryAction;
use App\Enum\Purchase\PurchasePriority;
use App\Enum\Purchase\PurchaseRequestKind;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\Purchase\PurchaseStepDecision;
use App\Enum\Purchase\PurchaseStepPurpose;
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
 *
 * В историю пишется каждое действие, а не только смена статуса: согласование
 * целиком идёт внутри ON_APPROVAL, а шаги при повторной подаче пересобираются,
 * так что след «кто что нажал» должен жить отдельно от них.
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
        $this->addHistory($request, $actor, null, PurchaseStatus::DRAFT, PurchaseHistoryAction::CREATED);
    }

    /**
     * DRAFT | REJECTED → ON_APPROVAL: собираем маршрут и запускаем его.
     * Повторная подача строит маршрут заново — сумма и состав могли измениться.
     * Маршрута этого вида в админке нет — подача не проходит: заявка без шагов
     * висела бы на согласовании, не стоя ни у кого.
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

        $this->transition($request, $actor, PurchaseStatus::ON_APPROVAL, PurchaseHistoryAction::SUBMITTED);
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

        // Исполнитель — тот, кто закрыл шаг ресёрча: он искал поставщика и
        // готовил документы. Раньше он назначался на первом шаге конвейера, но
        // платит теперь финдиректор, и исполнителем становился бы он.
        if ($request->getExecutor() === null && $step->getPurpose() === PurchaseStepPurpose::SOURCING) {
            $request->setExecutor($actor);
        }

        $this->addHistory(
            $request,
            $actor,
            $request->getStatus(),
            $request->getStatus(),
            PurchaseHistoryAction::STEP_APPROVED,
            $this->stepComment($step, $comment),
        );
        $this->em->flush();

        if ($request->getCurrentPosition() === null) {
            $this->transition($request, $actor, PurchaseStatus::APPROVED, PurchaseHistoryAction::STATUS_CHANGED);
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
     * Профильные замы приходят от директора списком и встают на зарезервированную
     * маршрутом позицию. Подписывать они будут по готовым документам, но
     * ответственными становятся сразу: заявка появляется у них в списке и им
     * уходит уведомление о назначении. Что каждому из них выдана роль
     * профильного зама, проверяет вызывающий — как и существование самих людей.
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

        // Замов отмечают на первичном решении: их позиция должна быть впереди.
        // С итогового шага назначить нельзя — указатель поехал бы назад; в
        // маршруте без слота замов назначать вообще некуда.
        $slot = $request->getApproversPosition();
        if ($approvers !== [] && ($slot === null || $step->getPosition() >= $slot)) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }

        $changes = $this->applyDirectorEdits($request, $itemEdits);

        if ($changes !== []) {
            $this->addHistory(
                $request,
                $actor,
                $request->getStatus(),
                $request->getStatus(),
                PurchaseHistoryAction::ITEMS_EDITED,
                'Состав правил директор: ' . implode('; ', $changes),
            );
        }

        $assigned = $this->routeBuilder->addApprovers($request, $approvers);

        if ($assigned !== []) {
            $this->addHistory(
                $request,
                $actor,
                $request->getStatus(),
                $request->getStatus(),
                PurchaseHistoryAction::APPROVERS_ASSIGNED,
                'Ответственные: ' . implode(', ', array_map($this->nameOf(...), $assigned)),
            );
        }

        // Подпись директора закрывает его позицию: указатель уедет в отдел закупок.
        $this->approveStep($request, $step, $actor);

        if ($assigned !== []) {
            $this->notifier->notifyApproversAssigned($request, $actor, $assigned);
        }
    }

    /**
     * Результат ресёрча отдела закупок: поставщик и реальные цены.
     *
     * Правится только пока заявка стоит на шаге закупок. Дальше нельзя: замы,
     * директор и финдиректор подписывают именно эти цифры, и менять их после
     * подписи значит подсунуть согласующим не то, что они видели.
     *
     * @param array<int, string> $priceEdits ключ — id позиции, значение — цена
     */
    public function applySourcing(PurchaseRequest $request, User $actor, ?string $supplier, array $priceEdits): void
    {
        if ($request->getStatus() !== PurchaseStatus::ON_APPROVAL
            || !$this->hasActivePurpose($request, PurchaseStepPurpose::SOURCING)
        ) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_STEP_NOT_ACTIVE);
        }

        $changes = [];

        if ($supplier !== null && $supplier !== $request->getSupplier()) {
            $request->setSupplier($supplier !== '' ? $supplier : null);
            $changes[] = $supplier !== ''
                ? sprintf('поставщик: %s', $supplier)
                : 'поставщик снят';
        }

        foreach ($request->getItems() as $item) {
            $price = $priceEdits[(int) $item->getId()] ?? null;
            if ($price === null) {
                continue;
            }
            if (!is_numeric($price) || (float) $price < 0) {
                throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_ITEM);
            }
            if ((float) $price === (float) $item->getEstimatedPrice()) {
                continue;
            }

            $changes[] = sprintf(
                'цена «%s»: %s → %s',
                (string) $item->getName(),
                (string) $item->getEstimatedPrice(),
                $price,
            );
            $item->setEstimatedPrice(number_format((float) $price, 2, '.', ''));
        }

        if ($changes === []) {
            return;
        }

        $this->addHistory(
            $request,
            $actor,
            $request->getStatus(),
            $request->getStatus(),
            PurchaseHistoryAction::SOURCING_UPDATED,
            implode('; ', $changes),
        );
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
     * Снять свою подпись — персональный откат для того, кто её поставил.
     *
     * Обычная подпись необратима: позиция закрылась, следующим ушли уведомления.
     * Но когда на позиции подписант один, она закрывается сразу, и «окна на
     * передумать» не остаётся вовсе — а ошибиться тогглом легко. Отзыв маршрута
     * это лечит, но требует второго человека.
     *
     * Поэтому здесь то же, что делает recall, но в границах одного человека:
     * сбрасываем его шаг и всё, что успело подписаться после него, и возвращаем
     * указатель на его позицию.
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

        $burned = $this->resetStepsFrom($request, $step->getPosition(), $step);

        $request->setStatus(PurchaseStatus::ON_APPROVAL);

        $this->addHistory(
            $request,
            $actor,
            $statusBefore,
            PurchaseStatus::ON_APPROVAL,
            PurchaseHistoryAction::STEP_REVOKED,
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

    /**
     * Вернуть заявку в отдел закупок со своего шага. Комментарий обязателен.
     *
     * Бухгалтерия и юристы бракуют не заявку, а пакет документов: возвращать её
     * автору незачем — он этих документов не готовил и починить их не может.
     * Поэтому заявка остаётся на согласовании и откатывается на шаг закупок,
     * а подписи, успевшие лечь после него, сбрасываются: пакет будет другой.
     */
    public function returnToDepartment(PurchaseRequest $request, PurchaseApprovalStep $step, User $actor, string $comment): void
    {
        $this->assertActiveStep($request, $step);

        if (trim($comment) === '') {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_COMMENT_REQUIRED);
        }

        $sourcing = $this->findSourcingStep($request);
        if ($sourcing === null || $sourcing->getPosition() >= $step->getPosition()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_STEP_NOT_FOUND);
        }

        $this->resetStepsFrom($request, $sourcing->getPosition());

        $this->addHistory(
            $request,
            $actor,
            $request->getStatus(),
            $request->getStatus(),
            PurchaseHistoryAction::RETURNED_TO_DEPARTMENT,
            $this->stepComment($step, $comment),
        );
        $this->em->flush();

        $this->notifier->notifyReturnedToDepartment($request, $actor, $comment);
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

        $this->transition(
            $request,
            $actor,
            PurchaseStatus::REJECTED,
            PurchaseHistoryAction::STEP_REJECTED,
            $this->stepComment($step, $comment),
        );
        $this->notifier->notifyRejected($request, $actor, $comment);
    }

    /**
     * Шаг конвейера исполнения: строго следующий статус.
     * Договора здесь больше нет — он стал шагом маршрута, и его файл требует
     * тот шаг.
     */
    public function advance(PurchaseRequest $request, User $actor, PurchaseStatus $target): void
    {
        if ($request->getStatus()->nextExecutionStatus() !== $target) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }

        $this->transition($request, $actor, $target, PurchaseHistoryAction::STATUS_CHANGED);

        if ($target === PurchaseStatus::DELIVERED) {
            $this->notifier->notifyDelivered($request, $actor);
        } else {
            $this->notifier->notifyStatusChanged($request, $actor);
        }
    }

    /**
     * DELIVERED → DONE — закрытие заявки в архив.
     *
     * Без УПД не закрываем: он единственное подтверждение, что закупка вообще
     * состоялась, и добыть его потом, когда заявка уехала в архив, некому.
     */
    public function confirm(PurchaseRequest $request, User $actor): void
    {
        $this->assertStatus($request, PurchaseStatus::DELIVERED);

        if (!$request->hasFileOfType(PurchaseFileType::UPD)) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_UPD_REQUIRED);
        }

        $this->transition($request, $actor, PurchaseStatus::DONE, PurchaseHistoryAction::STATUS_CHANGED);
        $this->notifier->notifyConfirmed($request, $actor);
    }

    /** Отмена из любого нефинального статуса. */
    public function cancel(PurchaseRequest $request, User $actor, ?string $comment): void
    {
        if ($request->getStatus()->isFinal()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }

        $this->transition($request, $actor, PurchaseStatus::CANCELLED, PurchaseHistoryAction::CANCELLED, $comment);
        $this->notifier->notifyCancelled($request, $actor, $comment);
    }

    /** Смена приоритета. */
    public function setPriority(PurchaseRequest $request, User $actor, PurchasePriority $priority): void
    {
        if ($request->getStatus()->isFinal()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }
        if ($request->getPriority() === $priority) {
            return;
        }

        $request->setPriority($priority);
        $this->addHistory(
            $request,
            $actor,
            $request->getStatus(),
            $request->getStatus(),
            PurchaseHistoryAction::PRIORITY_CHANGED,
            $priority->getLabel(),
        );
        $this->em->flush();
    }

    /** Запись в историю о чужом действии — файлы и классификация правятся вне этого сервиса. */
    public function log(PurchaseRequest $request, User $actor, PurchaseHistoryAction $action, ?string $comment = null): void
    {
        $this->addHistory($request, $actor, $request->getStatus(), $request->getStatus(), $action, $comment);
        $this->em->flush();
    }

    /**
     * Сбросить в PENDING все решённые шаги от указанной позиции и дальше.
     * Сгоревшие согласования видны только в history, поэтому вызывающий обязан
     * записать их туда той же транзакцией.
     *
     * @return list<string> описания сброшенных чужих подписей (кроме $exclude)
     */
    private function resetStepsFrom(PurchaseRequest $request, int $position, ?PurchaseApprovalStep $exclude = null): array
    {
        $burned = [];

        foreach ($request->getSteps() as $candidate) {
            if ($candidate->getPosition() < $position || !$candidate->getDecision()->isDecided()) {
                continue;
            }
            if ($candidate !== $exclude) {
                $burned[] = $this->describeStep($candidate);
            }
            $candidate->setDecision(PurchaseStepDecision::PENDING)
                ->setDecidedBy(null)
                ->setDecidedAt(null)
                ->setComment(null);
        }

        return $burned;
    }

    /** Шаг ресёрча — самый ранний, если их в маршруте несколько. */
    private function findSourcingStep(PurchaseRequest $request): ?PurchaseApprovalStep
    {
        $found = null;
        foreach ($request->getSteps() as $step) {
            if ($step->getPurpose() !== PurchaseStepPurpose::SOURCING) {
                continue;
            }
            if ($found === null || $step->getPosition() < $found->getPosition()) {
                $found = $step;
            }
        }

        return $found;
    }

    /** Заявка стоит на шаге такого назначения прямо сейчас. */
    private function hasActivePurpose(PurchaseRequest $request, PurchaseStepPurpose $purpose): bool
    {
        foreach ($request->getActiveSteps() as $step) {
            if ($step->getPurpose() === $purpose) {
                return true;
            }
        }

        return false;
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
            return $this->nameOf($user);
        }

        // Снимок названия роли, а не сама роль: её могли переименовать или
        // выключить, а история должна читаться как в день подписи.
        return $step->getRoleName() ?? 'Согласование';
    }

    /** Строка истории: с какого шага действие и что человек написал. */
    private function stepComment(PurchaseApprovalStep $step, ?string $comment): string
    {
        $description = $this->describeStep($step);
        $comment = $this->normalizeComment($comment);

        return $comment === null ? $description : $description . ' — ' . $comment;
    }

    private function nameOf(User $user): string
    {
        $name = trim(($user->getLastname() ?? '') . ' ' . ($user->getFirstname() ?? ''));

        return $name !== '' ? $name : (string) $user->getLogin();
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

    private function transition(
        PurchaseRequest $request,
        User $actor,
        PurchaseStatus $to,
        PurchaseHistoryAction $action,
        ?string $comment = null,
    ): void {
        $from = $request->getStatus();
        $request->setStatus($to);
        $this->addHistory($request, $actor, $from, $to, $action, $comment);
        $this->em->flush();
    }

    private function addHistory(
        PurchaseRequest $request,
        User $actor,
        ?PurchaseStatus $from,
        PurchaseStatus $to,
        PurchaseHistoryAction $action,
        ?string $comment = null,
    ): void {
        $entry = new PurchaseRequestHistory();
        $entry->setUser($actor);
        $entry->setAction($action);
        $entry->setFromStatus($from);
        $entry->setToStatus($to);
        $entry->setComment($this->normalizeComment($comment));
        $request->addHistory($entry);
        $this->em->persist($entry);
    }
}
