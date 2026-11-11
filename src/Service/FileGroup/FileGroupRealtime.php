<?php

declare(strict_types=1);

namespace App\Service\FileGroup;

use App\Entity\FileGroup\FileGroup;
use App\Entity\FileGroup\FileGroupFile;
use App\Entity\User\User;
use App\Service\Notification\NotificationPublisher;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Live-каталог группы и колокольчик о новых файлах.
 *
 * Mercure — пинг «каталог изменился», фронт сам refetch'ит detail.
 * Колокольчик — только upload, иначе delete/move зашумят участников.
 */
final class FileGroupRealtime
{
    private const MODULE = 'file_group';

    public function __construct(
        private readonly HubInterface $hub,
        private readonly NotificationPublisher $notifications,
    ) {
    }

    public function catalogChanged(FileGroup $group, User $actor, string $event): void
    {
        $groupId = $group->getId();
        if ($groupId === null) {
            return;
        }

        $this->publishMercure('/file-groups/' . $groupId, [
            'type' => 'catalog_changed',
            'event' => $event,
            'groupId' => $groupId,
            'senderId' => (int) $actor->getId(),
        ]);
    }

    public function groupDeleted(int $groupId, User $actor): void
    {
        $this->publishMercure('/file-groups/' . $groupId, [
            'type' => 'group_deleted',
            'groupId' => $groupId,
            'senderId' => (int) $actor->getId(),
        ]);
    }

    public function notifyFileUploaded(FileGroup $group, User $actor, FileGroupFile $file): void
    {
        $groupId = $group->getId();
        if ($groupId === null) {
            return;
        }

        $recipients = [$group->getOwner()];
        foreach ($group->getMembers() as $member) {
            $recipients[] = $member;
        }

        $this->notifications->publish(
            module: self::MODULE,
            event: 'file_uploaded',
            recipients: $recipients,
            title: sprintf('Новый файл «%s» в каталоге «%s»', $file->getOriginalName(), $group->getName()),
            link: $this->fileLink($groupId, $file),
            actor: $actor,
            typeLabel: 'Общие файлы',
        );
    }

    /** Колокольчик приглашённому: иначе каталог в «Доступные мне» виден только после ручного обновления списка. */
    public function notifyMemberAdded(FileGroup $group, User $actor, User $member): void
    {
        $groupId = $group->getId();
        if ($groupId === null) {
            return;
        }

        $this->notifications->publish(
            module: self::MODULE,
            event: 'member_added',
            recipients: [$member],
            title: sprintf('Вас добавили в каталог «%s»', $group->getName()),
            link: '/file-groups/' . $groupId,
            actor: $actor,
            typeLabel: 'Общие файлы',
        );
    }

    /** Исключённому: каталог пропадает из «Доступные мне», поэтому колокольчик обязателен. */
    public function notifyMemberRemoved(FileGroup $group, User $actor, User $member): void
    {
        $groupId = $group->getId();
        if ($groupId === null) {
            return;
        }

        $this->notifications->publish(
            module: self::MODULE,
            event: 'member_removed',
            recipients: [$member],
            title: sprintf('Вас убрали из каталога «%s»', $group->getName()),
            link: '/file-groups',
            actor: $actor,
            typeLabel: 'Общие файлы',
        );
    }

    public function notifyUploadGranted(FileGroup $group, User $actor, User $member): void
    {
        $groupId = $group->getId();
        if ($groupId === null) {
            return;
        }

        $this->notifications->publish(
            module: self::MODULE,
            event: 'upload_granted',
            recipients: [$member],
            title: sprintf('Вам разрешили загружать файлы в каталог «%s»', $group->getName()),
            link: '/file-groups/' . $groupId,
            actor: $actor,
            typeLabel: 'Общие файлы',
        );
    }

    public function notifyUploadRevoked(FileGroup $group, User $actor, User $member): void
    {
        $groupId = $group->getId();
        if ($groupId === null) {
            return;
        }

        $this->notifications->publish(
            module: self::MODULE,
            event: 'upload_revoked',
            recipients: [$member],
            title: sprintf('В каталоге «%s» снято право загружать файлы', $group->getName()),
            link: '/file-groups/' . $groupId,
            actor: $actor,
            typeLabel: 'Общие файлы',
        );
    }

    public function notifyWriterGranted(FileGroup $group, User $actor, User $member, FileGroupFile $file): void
    {
        $groupId = $group->getId();
        if ($groupId === null) {
            return;
        }

        $this->notifications->publish(
            module: self::MODULE,
            event: 'writer_granted',
            recipients: [$member],
            title: sprintf('Вам дали право редактировать «%s» в каталоге «%s»', $file->getOriginalName(), $group->getName()),
            link: $this->fileLink($groupId, $file),
            actor: $actor,
            typeLabel: 'Общие файлы',
        );
    }

    public function notifyWriterRevoked(FileGroup $group, User $actor, User $member, FileGroupFile $file): void
    {
        $groupId = $group->getId();
        if ($groupId === null) {
            return;
        }

        $this->notifications->publish(
            module: self::MODULE,
            event: 'writer_revoked',
            recipients: [$member],
            title: sprintf('У вас снято право редактировать «%s» в каталоге «%s»', $file->getOriginalName(), $group->getName()),
            link: $this->fileLink($groupId, $file),
            actor: $actor,
            typeLabel: 'Общие файлы',
        );
    }

    /** Ссылка сразу в папку файла: фронт читает `folder` и `file`. */
    private function fileLink(int $groupId, FileGroupFile $file): string
    {
        $query = ['file' => (string) $file->getId()];
        $folderId = $file->getFolder()?->getId();
        if ($folderId !== null) {
            $query['folder'] = (string) $folderId;
        }

        return '/file-groups/' . $groupId . '?' . http_build_query($query);
    }

    /** @param array<string, mixed> $payload */
    private function publishMercure(string $topic, array $payload): void
    {
        try {
            $this->hub->publish(new Update($topic, json_encode($payload, JSON_THROW_ON_ERROR)));
        } catch (\Throwable) {
            // ponytail: хаб лёг — каталог догонят кнопкой «Обновить»; API мутации не валим.
        }
    }
}
