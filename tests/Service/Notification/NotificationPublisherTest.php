<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification;

use App\Entity\User\User;
use App\Message\NotificationMessage;
use App\Service\Notification\NotificationPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class NotificationPublisherTest extends TestCase
{
    /** @var list<array{0: NotificationMessage, 1: list<object>}> */
    private array $dispatched = [];

    private NotificationPublisher $publisher;

    protected function setUp(): void
    {
        $this->dispatched = [];

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            function (object $message, array $stamps = []): Envelope {
                $this->dispatched[] = [$message, $stamps];

                return new Envelope($message);
            },
        );

        $this->publisher = new NotificationPublisher($bus);
    }

    public function testRoutingKeyIsBuiltFromModuleAndEvent(): void
    {
        $this->publisher->publish(
            module: 'document',
            event: 'incoming',
            recipients: [$this->user(187)],
            title: 'Новый входящий документ: Акт',
            link: '/document-in?doc=42',
            typeLabel: 'Новый входящий документ',
        );

        self::assertCount(1, $this->dispatched);
        [$message, $stamps] = $this->dispatched[0];

        // Модуль и событие живут только в ключе — в теле их нет,
        // чтобы у продюсера не было двух источников истины.
        $stamp = $stamps[0];
        self::assertInstanceOf(AmqpStamp::class, $stamp);
        self::assertSame('document.notification.incoming', $stamp->getRoutingKey());

        self::assertInstanceOf(NotificationMessage::class, $message);
        self::assertSame('Новый входящий документ: Акт', $message->title);
        self::assertSame('/document-in?doc=42', $message->link);
        self::assertSame([187], $message->recipients);
    }

    /** eventId нужен консьюмеру для дедупликации — без него повтор даст дубль. */
    public function testEventIdIsUuidV7AndUniquePerEvent(): void
    {
        $publish = fn () => $this->publisher->publish(
            module: 'document',
            event: 'incoming',
            recipients: [$this->user(187)],
            title: 'Акт',
        );

        $publish();
        $publish();

        [$first] = $this->dispatched[0];
        [$second] = $this->dispatched[1];

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $first->eventId,
            'ожидали uuid версии 7: у v4 вставки в индекс консьюмера ложатся вразброс',
        );
        self::assertNotSame($first->eventId, $second->eventId, 'id обязан быть свой на каждое событие');
    }

    /** Автор действия не должен получать уведомление о собственном действии. */
    public function testActorIsExcludedFromRecipients(): void
    {
        $actor = $this->user(144);

        $this->publisher->publish(
            module: 'document',
            event: 'comment_added',
            recipients: [$this->user(187), $actor, $this->user(1745)],
            title: 'Комментарий',
            actor: $actor,
        );

        [$message] = $this->dispatched[0];
        self::assertSame([187, 1745], $message->recipients);
        self::assertSame(144, $message->actorId);
    }

    /** Один человек попадает в список дважды — например, и как автор, и как участник. */
    public function testDuplicateRecipientsCollapse(): void
    {
        $this->publisher->publish(
            module: 'document',
            event: 'incoming',
            recipients: [$this->user(187), $this->user(187)],
            title: 'Акт',
        );

        [$message] = $this->dispatched[0];
        self::assertSame([187], $message->recipients);
    }

    /**
     * Некому показывать — незачем и публиковать. Иначе консьюмер получил бы
     * событие с пустым recipients и отклонил его как нарушение контракта.
     */
    public function testNothingIsPublishedWithoutRecipients(): void
    {
        $actor = $this->user(144);

        $this->publisher->publish(
            module: 'document',
            event: 'incoming',
            recipients: [$actor],
            title: 'Акт',
            actor: $actor,
        );

        $this->publisher->publish(
            module: 'document',
            event: 'incoming',
            recipients: [],
            title: 'Акт',
        );

        self::assertSame([], $this->dispatched);
    }

    private function user(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }
}
