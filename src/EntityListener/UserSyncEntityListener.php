<?php

namespace App\EntityListener;

use App\Entity\User\User;
use App\Message\UserSyncMessage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Doctrine Entity Listener на User — публикует события в RabbitMQ
 * при создании, обновлении релевантных полей или soft-delete.
 *
 * Релевантные поля для реплики: lastname, firstname, patronymic,
 * avatarName, deletedAt, login. Остальные изменения (пароль, email,
 * phone, lastSeenAt и т.д.) НЕ триггерят событие — Go они не нужны.
 *
 * Событие отправляется АСИНХРОННО через Messenger → AMQP-транспорт.
 * Routing key: 'user.upserted' или 'user.deleted' (topic exchange 'events').
 */
#[AsEntityListener(event: 'postPersist', entity: User::class)]
#[AsEntityListener(event: 'postUpdate', entity: User::class)]
#[AsEntityListener(event: 'preRemove', entity: User::class)]
final class UserSyncEntityListener
{
    /**
     * Поля, изменение которых триггерит отправку события.
     * Ключи — имена свойств Doctrine (не колонок БД).
     */
    private const array WATCHED_FIELDS = [
        'lastname',
        'firstname',
        'patronymic',
        'avatarName',
        'deletedAt',
        'login',
    ];

    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {}

    /**
     * Новый пользователь — всегда отправляем upserted.
     */
    public function postPersist(User $user, PostPersistEventArgs $event): void
    {
        $this->dispatch($user, 'upserted');
    }

    /**
     * Обновление — отправляем ТОЛЬКО если изменились релевантные поля.
     */
    public function postUpdate(User $user, PostUpdateEventArgs $event): void
    {
        $changeSet = $event->getObjectManager()
            ->getUnitOfWork()
            ->getEntityChangeSet($user);

        if (array_key_exists('deletedAt', $changeSet) && $user->getDeletedAt() !== null) {
            $this->dispatch($user, 'deleted');
            return;
        }

        // Проверяем, изменилось ли хотя бы одно из наблюдаемых полей
        foreach (self::WATCHED_FIELDS as $field) {
            if (array_key_exists($field, $changeSet)) {
                $this->dispatch($user, 'upserted');
                return;
            }
        }
    }

    /**
     * Удаление (hard delete, если случится) — отправляем deleted.
     *
     * NB: soft-delete через Gedmo перехватывается как UPDATE поля deletedAt
     * и отправляется как deleted в postUpdate. preRemove — страховка
     * на случай реального hard delete.
     */
    public function preRemove(User $user, PreRemoveEventArgs $event): void
    {
        $this->dispatch($user, 'deleted');
    }

    private function dispatch(User $user, string $eventType): void
    {
        $message = new UserSyncMessage(
            event: $eventType,
            userId: $user->getId(),
            login: $user->getLogin() ?? '',
            lastname: $user->getLastname() ?? '',
            firstname: $user->getFirstname() ?? '',
            patronymic: $user->getPatronymic(),
            avatarName: $user->getAvatarName(),
            deletedAt: $user->getDeletedAt(),
        );

        // AmqpStamp задаёт routing key для topic exchange:
        // 'user.upserted' или 'user.deleted'
        $this->messageBus->dispatch($message, [
            new AmqpStamp("user.{$eventType}"),
        ]);
    }
}
