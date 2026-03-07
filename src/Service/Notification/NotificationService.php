<?php

namespace App\Service\Notification;

use App\Entity\Notification\Notification;
use App\Entity\User\User;
use App\Enum\NotificationType;
use Doctrine\ORM\EntityManagerInterface;

class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    private function create(
        User $user,
        NotificationType $type,
        string $title,
        ?string $message = null,
        ?string $link = null,
        ?array $extra = null,
    ): Notification {
        $notification = new Notification();
        $notification->setUser($user);
        $notification->setType($type);
        $notification->setTitle($title);
        $notification->setMessage($message);
        $notification->setLink($link);
        $notification->setExtra($extra);

        $this->em->persist($notification);

        return $notification;
    }

    public function notifyDocumentSent(User $recipient, string $documentTitle, string $link): void
    {
        $this->create(
            $recipient,
            NotificationType::DOCUMENT_SENT,
            'Документ отправлен: ' . $documentTitle,
            link: $link,
        );
        $this->em->flush();
    }

    public function notifyNewIncomingDocument(User $recipient, string $documentTitle, string $link): void
    {
        $this->create(
            $recipient,
            NotificationType::NEW_INCOMING_DOCUMENT,
            'Новый входящий документ: ' . $documentTitle,
            link: $link,
        );
        $this->em->flush();
    }

    public function notifyTaskAssigned(User $recipient, string $taskTitle, string $link): void
    {
        $this->create(
            $recipient,
            NotificationType::TASK_ASSIGNED,
            'Вам назначена задача: ' . $taskTitle,
            link: $link,
        );
        $this->em->flush();
    }

    public function notifyTaskMoved(User $recipient, string $taskTitle, string $columnName, string $link): void
    {
        $this->create(
            $recipient,
            NotificationType::TASK_MOVED,
            'Задача перемещена: ' . $taskTitle . ' → ' . $columnName,
            link: $link,
        );
        $this->em->flush();
    }

    public function notifyTaskCommentAdded(User $recipient, string $taskTitle, string $link): void
    {
        $this->create(
            $recipient,
            NotificationType::TASK_COMMENT_ADDED,
            'Новый комментарий в задаче: ' . $taskTitle,
            link: $link,
        );
        $this->em->flush();
    }
}
