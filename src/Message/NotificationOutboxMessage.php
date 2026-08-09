<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Конверт уведомления в outbox.
 *
 * Зачем нужен отдельный тип, а не NotificationMessage со стампом.
 *
 * Раньше продюсер сразу диспатчил NotificationMessage в AMQP-транспорт с
 * AmqpStamp, в котором лежал routing key. Между коммитом бизнес-изменения и
 * публикацией не было ничего: недоступен брокер — событие потеряно навсегда,
 * а документ при этом уже опубликован и лежит у получателей.
 *
 * Теперь продюсер кладёт сообщение в транспорт на Doctrine, то есть в ту же
 * базу, что и бизнес-данные. Отдельный воркер перекладывает его в AMQP и
 * повторяет попытки, пока брокер не поднимется.
 *
 * Routing key нельзя протащить через AmqpStamp: он реализует
 * NonSendableStampInterface и до транспорта не доезжает. Класть его в тело
 * NotificationMessage тоже нельзя — это тело разбирает Go-сервис, и лишнее
 * поле сломало бы контракт. Поэтому ключ едет в отдельном конверте, который
 * дальше outbox не уходит.
 */
final readonly class NotificationOutboxMessage
{
    public function __construct(
        public string $routingKey,
        public NotificationMessage $notification,
    ) {
    }
}
