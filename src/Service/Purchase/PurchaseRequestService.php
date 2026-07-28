<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestApprover;
use App\Entity\Purchase\PurchaseRequestHistory;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchasePriority;
use App\Enum\Purchase\PurchaseStatus;
use App\Repository\Purchase\PurchaseSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Переходы статусов заявки на закупку.
 *
 * Права («кто может») проверяет ролевой гейт контроллера;
 * здесь — только корректность перехода («можно ли из текущего статуса»),
 * запись в историю и публикация уведомления. Каждый метод делает flush.
 */
final class PurchaseRequestService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PurchaseNotificationPublisher $notifier,
        private readonly PurchaseSettingRepository $settings,
    ) {}

    /** Запись о создании заявки (from = NULL). Без flush — вызывается при создании. */
    public function logCreated(PurchaseRequest $request, User $actor): void
    {
        $this->addHistory($request, $actor, null, PurchaseStatus::DRAFT);
    }

    /** DRAFT | REJECTED → NEW (на рассмотрение отделом закупок). */
    public function submit(PurchaseRequest $request, User $actor): void
    {
        $from = $request->getStatus();
        if (!$from->isEditable()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }
        if ($request->getItems()->isEmpty()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_ITEMS_REQUIRED);
        }
        // Пояснительная записка обязательна: текстом или файлом
        if (trim((string) $request->getJustification()) === ''
            && !$request->hasFileOfType(PurchaseFileType::JUSTIFICATION)
        ) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_JUSTIFICATION_REQUIRED);
        }

        // Повторная подача после возврата: заявка могла измениться — согласуют заново.
        if ($from === PurchaseStatus::REJECTED) {
            foreach ($request->getApprovers() as $approver) {
                $approver->setConfirmedAt(null);
            }
        }

        $this->transition($request, $actor, PurchaseStatus::NEW);
        $this->notifier->notifySubmitted($request, $actor, resubmitted: $from === PurchaseStatus::REJECTED);

        // Ответственные всех затронутых категорий (шапка + категории позиций из справочника)
        // автоматически становятся согласантами (кроме самого автора).
        foreach ($this->collectResponsibles($request) as $responsible) {
            if ($responsible->getId() !== $actor->getId()) {
                $this->inviteApprover($request, $actor, $responsible);
            }
        }
    }

    /**
     * Ответственные категорий заявки: категория шапки + категории номенклатурных позиций.
     * @return list<User>
     */
    private function collectResponsibles(PurchaseRequest $request): array
    {
        $byId = [];

        $headerResponsible = $request->getCategory()?->getResponsibleUser();
        if ($headerResponsible !== null) {
            $byId[$headerResponsible->getId()] = $headerResponsible;
        }

        foreach ($request->getItems() as $item) {
            $responsible = $item->getCategoryItem()?->getCategory()?->getResponsibleUser();
            if ($responsible !== null) {
                $byId[$responsible->getId()] = $responsible;
            }
        }

        return array_values($byId);
    }

    /** NEW → APPROVERS_PENDING: отдел закупок отправляет заявку согласантам (нужен хотя бы один). */
    public function sendToApprovers(PurchaseRequest $request, User $actor): void
    {
        $this->assertStatus($request, PurchaseStatus::NEW);

        if ($request->getApprovers()->isEmpty()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_APPROVERS_REQUIRED);
        }

        $this->transition($request, $actor, PurchaseStatus::APPROVERS_PENDING);
    }

    /**
     * NEW (без согласантов) | APPROVERS_DONE → CEO_APPROVE_PENDING.
     * Гейт: все согласанты должны подтвердить — до этого директор заявку не видит.
     *
     * Единственная точка, где заявка попадает к директору, поэтому порог
     * автосогласования проверяется здесь и больше нигде.
     */
    public function sendToDirector(PurchaseRequest $request, User $actor): void
    {
        $allowed = [PurchaseStatus::NEW, PurchaseStatus::APPROVERS_DONE];
        if (!in_array($request->getStatus(), $allowed, true)) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }

        foreach ($request->getApprovers() as $approver) {
            if ($approver->getConfirmedAt() === null) {
                throw new PurchaseTransitionException(SpaApiError::PURCHASE_APPROVALS_PENDING);
            }
        }

        // Мелкие заявки директора не беспокоят: сразу CEO_APPROVED с отметкой в истории.
        $minAmount = $this->settings->getCeoApproveMinAmount();
        if ($minAmount > 0 && $request->getTotalAmount() < $minAmount) {
            $this->transition(
                $request,
                $actor,
                PurchaseStatus::CEO_APPROVED,
                sprintf('Автосогласовано: сумма ниже порога %s ₽', number_format($minAmount, 2, ',', ' ')),
            );
            $this->notifier->notifyApproved($request, $actor);

            return;
        }

        $this->transition($request, $actor, PurchaseStatus::CEO_APPROVE_PENDING);
        $this->notifier->notifySentToDirector($request, $actor);
    }

    /**
     * CEO_APPROVE_PENDING → CEO_APPROVED (+опционально срочность).
     * Только с этапа директора: до него заявка проходит рассмотрение и всех согласантов.
     */
    public function approve(PurchaseRequest $request, User $actor, ?PurchasePriority $priority = null): void
    {
        $this->assertStatus($request, PurchaseStatus::CEO_APPROVE_PENDING);

        if ($priority !== null) {
            $request->setPriority($priority);
        }

        $this->transition($request, $actor, PurchaseStatus::CEO_APPROVED);
        $this->notifier->notifyApproved($request, $actor);
    }

    /**
     * Возврат на доработку, комментарий обязателен.
     * Отдел закупок — с рассмотрения и согласования; директор — со своего этапа
     * и из CEO_APPROVED, пока не отправлен счёт («передумал»).
     */
    public function reject(PurchaseRequest $request, User $actor, string $comment): void
    {
        $allowed = [
            PurchaseStatus::NEW,
            PurchaseStatus::APPROVERS_PENDING,
            PurchaseStatus::CEO_APPROVE_PENDING,
            PurchaseStatus::CEO_APPROVED,
        ];
        if (!in_array($request->getStatus(), $allowed, true)) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }
        if (trim($comment) === '') {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_COMMENT_REQUIRED);
        }

        $this->transition($request, $actor, PurchaseStatus::REJECTED, $comment);
        $this->notifier->notifyRejected($request, $actor, $comment);
    }

    /** Пригласить согласанта (идемпотентно: повторное приглашение возвращает существующую запись). */
    public function inviteApprover(PurchaseRequest $request, User $actor, User $invited): PurchaseRequestApprover
    {
        $existing = $request->findApproverFor($invited);
        if ($existing !== null) {
            return $existing;
        }

        $approver = new PurchaseRequestApprover();
        $approver->setUser($invited);
        $approver->setInvitedBy($actor);
        $request->addApprover($approver);
        $this->em->persist($approver);
        $this->em->flush();

        $this->notifier->notifyApproverInvited($request, $actor, $invited);

        return $approver;
    }

    /**
     * Убрать приглашённого согласанта (клапан от «молчуна»).
     * Если после удаления неподтверждённых не осталось — этап согласования закрывается автоматически.
     */
    public function removeApprover(PurchaseRequest $request, PurchaseRequestApprover $approver, User $actor): void
    {
        $request->removeApprover($approver);
        $this->em->remove($approver);
        $this->em->flush();

        $this->advanceApproversStageIfComplete($request, $actor);
    }

    /**
     * Тоггл согласанта: подтвердил / снял подтверждение.
     * Подтвердил последний → заявка сама переходит в APPROVERS_DONE.
     */
    public function confirmApproval(PurchaseRequest $request, PurchaseRequestApprover $approver, bool $confirmed): void
    {
        $approver->setConfirmedAt($confirmed ? new \DateTimeImmutable() : null);
        $this->em->flush();

        if ($confirmed && $approver->getUser() !== null) {
            $this->notifier->notifyApproverConfirmed($request, $approver->getUser(), $approver->getInvitedBy());
            $this->advanceApproversStageIfComplete($request, $approver->getUser());
        }
    }

    /** APPROVERS_PENDING + все подтвердили → APPROVERS_DONE (автопереход). */
    private function advanceApproversStageIfComplete(PurchaseRequest $request, User $actor): void
    {
        if ($request->getStatus() !== PurchaseStatus::APPROVERS_PENDING) {
            return;
        }
        foreach ($request->getApprovers() as $approver) {
            if ($approver->getConfirmedAt() === null) {
                return;
            }
        }

        $this->transition($request, $actor, PurchaseStatus::APPROVERS_DONE);
    }

    /**
     * Шаг конвейера исполнения: строго следующий статус.
     * На первом шаге (CEO_APPROVED → INVOICE_SENT) назначается исполнитель, если его ещё нет.
     */
    public function advance(PurchaseRequest $request, User $actor, PurchaseStatus $target): void
    {
        if ($request->getStatus()->nextExecutionStatus() !== $target) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }

        if ($target === PurchaseStatus::INVOICE_SENT && $request->getExecutor() === null) {
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
        $entry->setComment($comment !== null && trim($comment) !== '' ? $comment : null);
        $request->addHistory($entry);
        $this->em->persist($entry);
    }
}
