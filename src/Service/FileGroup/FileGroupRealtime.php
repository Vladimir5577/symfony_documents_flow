<?php

declare(strict_types=1);

namespace App\Service\FileGroup;

use App\Entity\FileGroup\FileGroup;
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

    public function notifyFileUploaded(FileGroup $group, User $actor, string $fileName): void
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
            title: sprintf('Новый файл «%s» в группе «%s»', $fileName, $group->getName()),
            link: '/file-groups/' . $groupId,
            actor: $actor,
            typeLabel: 'Общие файлы',
        );
    }

    /** Колокольчик приглашённому: иначе группа в «Доступные мне» видна только после ручного обновления списка. */
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
            title: sprintf('Вас добавили в группу «%s»', $group->getName()),
            link: '/file-groups/' . $groupId,
            actor: $actor,
            typeLabel: 'Общие файлы',
        );
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
