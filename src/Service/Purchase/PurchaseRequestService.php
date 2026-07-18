<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestHistory;
use App\Entity\User\User;
use App\Enum\Purchase\PurchasePriority;
use App\Enum\Purchase\PurchaseStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Переходы статусов заявки на закупку.
 *
 * Права («кто может») проверяет PurchaseRequestVoter в контроллере;
 * здесь — только корректность перехода («можно ли из текущего статуса»),
 * запись в историю и публикация уведомления. Каждый метод делает flush.
 */
final class PurchaseRequestService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PurchaseNotificationPublisher $notifier,
    ) {}

    /** Запись о создании заявки (from = NULL). Без flush — вызывается при создании. */
    public function logCreated(PurchaseRequest $request, User $actor): void
    {
        $this->addHistory($request, $actor, null, PurchaseStatus::DRAFT);
    }

    /** DRAFT | REJECTED → PENDING_APPROVAL. */
    public function submit(PurchaseRequest $request, User $actor): void
    {
        $from = $request->getStatus();
        if (!$from->isEditable()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }
        if ($request->getItems()->isEmpty()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_ITEMS_REQUIRED);
        }

        $this->transition($request, $actor, PurchaseStatus::PENDING_APPROVAL);
        $this->notifier->notifySubmitted($request, $actor, resubmitted: $from === PurchaseStatus::REJECTED);
    }

    /** PENDING_APPROVAL → APPROVED (+опционально срочность). */
    public function approve(PurchaseRequest $request, User $actor, ?PurchasePriority $priority = null): void
    {
        $this->assertStatus($request, PurchaseStatus::PENDING_APPROVAL);

        if ($priority !== null) {
            $request->setPriority($priority);
        }

        $this->transition($request, $actor, PurchaseStatus::APPROVED);
        $this->notifier->notifyApproved($request, $actor);
    }

    /** PENDING_APPROVAL → REJECTED, комментарий обязателен. */
    public function reject(PurchaseRequest $request, User $actor, string $comment): void
    {
        $this->assertStatus($request, PurchaseStatus::PENDING_APPROVAL);
        if (trim($comment) === '') {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_COMMENT_REQUIRED);
        }

        $this->transition($request, $actor, PurchaseStatus::REJECTED, $comment);
        $this->notifier->notifyRejected($request, $actor, $comment);
    }

    /** APPROVED → IN_PROGRESS, назначает исполнителя. */
    public function take(PurchaseRequest $request, User $actor): void
    {
        $this->assertStatus($request, PurchaseStatus::APPROVED);

        $request->setExecutor($actor);
        $this->transition($request, $actor, PurchaseStatus::IN_PROGRESS);
        $this->notifier->notifyTaken($request, $actor);
    }

    /** Шаг конвейера исполнения: строго следующий статус. */
    public function advance(PurchaseRequest $request, User $actor, PurchaseStatus $target): void
    {
        if ($request->getStatus()->nextExecutionStatus() !== $target) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
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
