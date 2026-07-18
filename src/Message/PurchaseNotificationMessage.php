<?php

namespace App\Message;

/**
 * Событие уведомления модуля закупок для межсервисной шины (RabbitMQ).
 *
 * Потребляется сервисом нотификаций (go_notification_service_document_flow),
 * который создаёт по записи уведомления на каждого получателя из recipients.
 *
 * Routing key: purchase.notification.{type}
 * (topic exchange 'events', см. messenger.yaml → event_bus).
 *
 * Форма полей совпадает с Go-структурой PurchaseNotificationEvent.
 */
final readonly class PurchaseNotificationMessage
{
    /**
     * @param string               $type       submitted | approved | rejected | taken | status_changed | delivered | confirmed | cancelled | comment_added
     * @param int                  $actorId    кто совершил действие
     * @param int                  $purchaseId PurchaseRequest.id
     * @param list<int>            $recipients id пользователей-получателей
     * @param array<string, mixed> $data       данные для построения текста уведомления (purchaseTitle, actorName, comment, status, link...)
     */
    public function __construct(
        public string $type,
        public int    $actorId,
        public int    $purchaseId,
        public array  $recipients,
        public array  $data = [],
    ) {}
}
