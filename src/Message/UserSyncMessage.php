<?php

namespace App\Message;

/**
 * Событие синхронизации пользователя для межсервисной шины (RabbitMQ).
 *
 * Отправляется при создании/обновлении/удалении пользователя.
 * Go-канбан (и будущие микросервисы) потребляют это сообщение
 * для поддержания локальной реплики таблицы users.
 *
 * Routing key определяется по event:
 *   'upserted' → user.upserted (создание или обновление)
 *   'deleted'  → user.deleted  (soft-delete)
 */
final readonly class UserSyncMessage
{
    /**
     * @param string                  $event      'upserted' | 'deleted'
     * @param int                     $userId     User.id
     * @param string                  $login      User.login
     * @param string                  $lastname   User.lastname
     * @param string                  $firstname  User.firstname
     * @param string|null             $patronymic User.patronymic
     * @param string|null             $avatarName User.avatarName (имя файла аватара)
     * @param \DateTimeImmutable|null $deletedAt  User.deletedAt (soft-delete timestamp)
     */
    public function __construct(
        public string              $event,
        public int                 $userId,
        public string              $login,
        public string              $lastname,
        public string              $firstname,
        public ?string             $patronymic = null,
        public ?string             $avatarName = null,
        public ?\DateTimeImmutable $deletedAt = null,
    ) {}
}
