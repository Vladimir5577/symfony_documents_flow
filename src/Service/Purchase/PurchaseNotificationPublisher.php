<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Entity\Purchase\PurchaseRequest;
use App\Entity\User\User;
use App\Enum\User\UserRole;
use App\Message\PurchaseNotificationMessage;
use App\Repository\User\UserRepository;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Публикует события уведомлений закупок в RabbitMQ (topic exchange 'events').
 * Записи уведомлений создаёт сервис нотификаций — по одной на получателя.
 */
final class PurchaseNotificationPublisher
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly UserRepository $userRepository,
    ) {}

    /** Подана (или повторно подана) на согласование — директорам. */
    public function notifySubmitted(PurchaseRequest $request, User $actor, bool $resubmitted): void
    {
        $this->publish('submitted', $request, $actor, $this->directors(), [
            'resubmitted' => $resubmitted,
        ]);
    }

    /** Согласована — менеджерам департамента и отделу закупок. */
    public function notifyApproved(PurchaseRequest $request, User $actor): void
    {
        $recipients = array_merge($this->departmentManagers($request), $this->purchaseDepartment());
        $this->publish('approved', $request, $actor, $recipients);
    }

    /** Возвращена на доработку — менеджерам департамента. */
    public function notifyRejected(PurchaseRequest $request, User $actor, string $comment): void
    {
        $this->publish('rejected', $request, $actor, $this->departmentManagers($request), [
            'comment' => $comment,
        ]);
    }

    /** Взята в работу — менеджерам департамента. */
    public function notifyTaken(PurchaseRequest $request, User $actor): void
    {
        $this->publish('taken', $request, $actor, $this->departmentManagers($request));
    }

    /** Продвижение по конвейеру исполнения — менеджерам департамента. */
    public function notifyStatusChanged(PurchaseRequest $request, User $actor): void
    {
        $this->publish('status_changed', $request, $actor, $this->departmentManagers($request));
    }

    /** Доставлено, пора принимать — менеджерам департамента. */
    public function notifyDelivered(PurchaseRequest $request, User $actor): void
    {
        $this->publish('delivered', $request, $actor, $this->departmentManagers($request));
    }

    /** Департамент подтвердил получение — исполнителю. */
    public function notifyConfirmed(PurchaseRequest $request, User $actor): void
    {
        $this->publish('confirmed', $request, $actor, array_filter([$request->getExecutor()]));
    }

    /** Отменена — всем участникам процесса. */
    public function notifyCancelled(PurchaseRequest $request, User $actor, ?string $comment): void
    {
        $recipients = array_merge(
            $this->departmentManagers($request),
            $this->directors(),
            array_filter([$request->getExecutor()]),
        );
        $this->publish('cancelled', $request, $actor, $recipients, [
            'comment' => $comment,
        ]);
    }

    /** Новый комментарий — автору заявки и исполнителю. */
    public function notifyCommentAdded(PurchaseRequest $request, User $actor): void
    {
        $recipients = array_filter([$request->getCreatedBy(), $request->getExecutor()]);
        $this->publish('comment_added', $request, $actor, $recipients);
    }

    /**
     * @param list<User>           $recipients
     * @param array<string, mixed> $data
     */
    private function publish(string $type, PurchaseRequest $request, User $actor, array $recipients, array $data = []): void
    {
        $recipientIds = [];
        foreach ($recipients as $recipient) {
            $id = $recipient->getId();
            if ($id !== null && $id !== $actor->getId()) {
                $recipientIds[$id] = $id;
            }
        }

        if ($recipientIds === []) {
            return;
        }

        $actorName = trim(($actor->getLastname() ?? '') . ' ' . ($actor->getFirstname() ?? '')) ?: (string) $actor->getLogin();

        $message = new PurchaseNotificationMessage(
            type: $type,
            actorId: (int) $actor->getId(),
            purchaseId: (int) $request->getId(),
            recipients: array_values($recipientIds),
            data: $data + [
                'purchaseTitle' => (string) $request->getTitle(),
                'actorName' => $actorName,
                'status' => $request->getStatus()->value,
                'statusLabel' => $request->getStatus()->getLabel(),
                'link' => '/purchases/' . $request->getId(),
            ],
        );

        $this->messageBus->dispatch($message, [
            new AmqpStamp('purchase.notification.' . $type),
        ]);
    }

    /** @return list<User> */
    private function directors(): array
    {
        return $this->userRepository->findByRoleName(UserRole::ROLE_PURCHASE_DIRECTOR->value);
    }

    /** @return list<User> */
    private function purchaseDepartment(): array
    {
        return $this->userRepository->findByRoleName(UserRole::ROLE_PURCHASE_DEPARTMENT->value);
    }

    /** @return list<User> */
    private function departmentManagers(PurchaseRequest $request): array
    {
        $organization = $request->getOrganization();
        if ($organization === null) {
            return [];
        }

        return $this->userRepository->findByRoleName(UserRole::ROLE_MANAGER->value, $organization);
    }
}
