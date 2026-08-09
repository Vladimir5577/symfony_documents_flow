<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\NotificationOutboxMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Перекладывает уведомления из outbox в шину событий.
 *
 * Работает под `messenger:consume outbox`. Достаёт из конверта routing key,
 * возвращает его в AmqpStamp и отправляет уже само тело уведомления —
 * ровно то, которое разбирает go_notification_service.
 *
 * Если брокер недоступен, dispatch бросает исключение, сообщение остаётся в
 * очереди outbox и повторяется по политике ретраев транспорта. Событие не
 * теряется: оно лежит в той же базе, что и бизнес-данные.
 */
#[AsMessageHandler]
final readonly class NotificationOutboxRelayHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(NotificationOutboxMessage $message): void
    {
        $this->messageBus->dispatch(
            $message->notification,
            [new AmqpStamp($message->routingKey)],
        );
    }
}
