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

    /**
     * @param User[] $recipients
     */
    public function notifyNewIncomingDocumentToRecipients(array $recipients, string $documentTitle, string $link): void
    {
        foreach ($recipients as $recipient) {
            $this->create(
                $recipient,
                NotificationType::NEW_INCOMING_DOCUMENT,
                'Новый входящий документ: ' . $documentTitle,
                link: $link,
            );
        }
        $this->em->flush();
    }

    public function notifyKanbanTaskAssigned(User $recipient, string $title, string $link, bool $isSubtask = false): void
    {
        $message = $isSubtask
            ? 'Вам назначена подзадача: ' . $title
            : 'Вам назначена задача: ' . $title;
        $this->create(
            $recipient,
            NotificationType::KANBAN_TASK_ASSIGNED_TO_USER,
            $message,
            link: $link,
        );
        $this->em->flush();
    }

    public function notifyNewKanbanProjectUser(User $recipient, string $projectName, string $link): void
    {
        $this->create(
            $recipient,
            NotificationType::USER_ADDED_TO_KANBAN_PROJECT,
            'Вас добавили в проект «' . $projectName . '»',
            link: $link,
        );
        $this->em->flush();
    }

    public function notifyUserRemovedFromKanbanProject(User $recipient, string $projectName, string $link): void
    {
        $this->create(
            $recipient,
            NotificationType::USER_REMOVED_FROM_KANBAN_PROJECT,
            'Вас исключили из проекта «' . $projectName . '»',
            link: $link,
        );
        $this->em->flush();
    }

    public function notifyTaskMoved(
        User $recipient,
        string $taskTitle,
        string $authorName,
        string $fromColumnTitle,
        string $toColumnTitle,
        string $link,
    ): void {
        $title = trim($authorName) . ' переместил задачу ' . $taskTitle . ' из колонки «' . $fromColumnTitle . '» в колонку «' . $toColumnTitle . '»';
        $this->create(
            $recipient,
            NotificationType::TASK_MOVED,
            $title,
            link: $link,
        );
        $this->em->flush();
    }

    /**
     * @param User[] $recipients
     */
    public function notifyTaskMovedToRecipients(
        array $recipients,
        string $taskTitle,
        string $authorName,
        string $fromColumnTitle,
        string $toColumnTitle,
        string $link,
    ): void {
        $title = trim($authorName) . ' переместил задачу ' . $taskTitle . ' из колонки «' . $fromColumnTitle . '» в колонку «' . $toColumnTitle . '»';
        foreach ($recipients as $recipient) {
            $this->create(
                $recipient,
                NotificationType::TASK_MOVED,
                $title,
                link: $link,
            );
        }
        $this->em->flush();
    }

    public function notifyTaskCommentAdded(User $recipient, string $authorName, string $taskTitle, string $link): void
    {
        $title = trim($authorName) . ' оставил комментарий к задаче ' . $taskTitle;
        $this->create(
            $recipient,
            NotificationType::TASK_COMMENT_ADDED,
            $title,
            link: $link,
        );
        $this->em->flush();
    }

    public function notifyGeneric(User $recipient, string $title, string $link): void
    {
        $this->create(
            $recipient,
            NotificationType::GENERIC,
            $title,
            link: $link,
        );
        $this->em->flush();
    }
}
