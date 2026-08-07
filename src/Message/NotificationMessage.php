<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Уведомление для межсервисной шины (RabbitMQ, topic exchange 'events').
 *
 * Одно сообщение на все модули монолита: закупки, документооборот и всё, что
 * появится дальше. Потребляет go_notification_service_document_flow, создавая
 * по записи на каждого получателя из recipients.
 *
 * Routing key: {модуль}.notification.{событие} — ставится AmqpStamp'ом в
 * NotificationPublisher. Модуль и тип события в теле НЕ дублируются: сервис
 * берёт их из ключа, чтобы не было двух источников истины.
 *
 * Имена свойств = имена полей JSON на проводе, их разбирает Go-структура
 * NotificationEvent — переименование здесь молча ломает приём на той стороне.
 * Она же и есть спецификация контракта:
 * go_notification_service_document_flow/internal/messaging/events/notification.go
 */
final readonly class NotificationMessage
{
    /**
     * @param string               $eventId   uuid v7, один на событие; по нему консьюмер отсекает дубли
     * @param list<int>            $recipients id пользователей-получателей
     * @param string               $title     готовый заголовок уведомления
     * @param string               $typeLabel подпись категории для списка («Новый входящий документ»)
     * @param string|null          $message   дополнительный текст, если есть
     * @param string|null          $link      маршрут SPA, куда ведёт уведомление
     * @param int                  $actorId   кто совершил действие (для справки, из получателей уже исключён)
     * @param array<string, mixed> $data      непрозрачные данные, уезжают в extra как есть
     */
    public function __construct(
        public string $eventId,
        public array $recipients,
        public string $title,
        public string $typeLabel = '',
        public ?string $message = null,
        public ?string $link = null,
        public int $actorId = 0,
        public array $data = [],
    ) {}
}
