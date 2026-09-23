<?php

declare(strict_types=1);

namespace App\Tests\Service\Purchase;

use App\Entity\Purchase\PurchaseRequest;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\User\UserRole;
use App\Message\NotificationMessage;
use App\Repository\Purchase\PurchaseApproverRoleRepository;
use App\Repository\User\UserRepository;
use App\Service\Purchase\PurchaseRoster;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Пойманные с шины уведомления закупки: зрители заявки, актор исключён.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait PurchaseNotificationAssertions
{
    /** Носители VIEW_ALL — стабильные id, чтобы не пересечься с актёрами сценариев 1–9. */
    private const VIEWER_DIRECTOR = 101;
    private const VIEWER_PURCHASE = 102;
    private const VIEWER_FINANCE = 103;
    private const VIEWER_ADMIN = 106;

    /** @var list<array{0: NotificationMessage, 1: string}> */
    private array $purchaseNotifications = [];

    private function capturePurchaseBus(): MessageBusInterface
    {
        $this->purchaseNotifications = [];

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            function (object $message, array $stamps = []): Envelope {
                $event = '';
                foreach ($stamps as $stamp) {
                    if ($stamp instanceof AmqpStamp) {
                        $key = (string) $stamp->getRoutingKey();
                        $event = str_starts_with($key, 'purchase.notification.')
                            ? substr($key, strlen('purchase.notification.'))
                            : $key;
                    }
                }
                if ($message instanceof NotificationMessage) {
                    $this->purchaseNotifications[] = [$message, $event];
                }

                return new Envelope($message);
            },
        );

        return $bus;
    }

    private function purchaseRoster(): PurchaseRoster
    {
        $map = [
            PurchaseRoleCode::DIRECTOR->value => self::VIEWER_DIRECTOR,
            PurchaseRoleCode::PURCHASE_DEPARTMENT->value => self::VIEWER_PURCHASE,
            PurchaseRoleCode::FINANCE_DIRECTOR->value => self::VIEWER_FINANCE,
            PurchaseRoleCode::ACCOUNTING->value => 104,
            PurchaseRoleCode::LEGAL->value => 105,
        ];

        $approverRoles = $this->createStub(PurchaseApproverRoleRepository::class);
        $approverRoles->method('findRoleCodesForUser')->willReturn([]);
        $approverRoles->method('findUsersByRoleCodes')->willReturnCallback(
            function (array $codes) use ($map): array {
                $users = [];
                foreach ($codes as $code) {
                    $key = $code instanceof PurchaseRoleCode ? $code->value : (string) $code;
                    if (isset($map[$key])) {
                        $users[$map[$key]] = $this->user($map[$key]);
                    }
                }

                return array_values($users);
            },
        );

        return new PurchaseRoster($approverRoles);
    }

    private function purchaseUsers(): UserRepository
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findByRoleName')->willReturnCallback(
            function (string $roleName): array {
                return $roleName === UserRole::ROLE_ADMIN->value
                    ? [$this->user(self::VIEWER_ADMIN)]
                    : [];
            },
        );

        return $users;
    }

    /** Рассылка после изменения: есть письма, актор исключён, зрители и автор на рассылке. */
    private function assertPurchaseNotified(PurchaseRequest $request, User $actor): void
    {
        self::assertNotEmpty($this->purchaseNotifications, 'изменение заявки должно рассылать уведомления');

        foreach ($this->purchaseNotifications as [$message, $event]) {
            self::assertSame('/purchases/' . $request->getId(), $message->link, $event);
            self::assertNotContains($actor->getId(), $message->recipients, $event . ': актор в получателях');

            if (in_array($event, ['stage_activated', 'approvers_assigned'], true)) {
                continue;
            }

            $authorId = $request->getCreatedBy()?->getId();
            if ($authorId !== null && $authorId !== $actor->getId()) {
                self::assertContains($authorId, $message->recipients, $event . ': нет автора');
            }
            $viewers = $request->getStatus() === PurchaseStatus::DRAFT
                ? [self::VIEWER_ADMIN]
                : [self::VIEWER_DIRECTOR, self::VIEWER_PURCHASE, self::VIEWER_FINANCE, self::VIEWER_ADMIN];
            foreach ($viewers as $viewerId) {
                if ($viewerId !== $actor->getId()) {
                    self::assertContains($viewerId, $message->recipients, $event . ': нет зрителя ' . $viewerId);
                }
            }
        }

        $this->purchaseNotifications = [];
    }

    private function assertNoPurchaseNotifications(): void
    {
        self::assertSame(
            [],
            array_column($this->purchaseNotifications, 1),
            'это действие не шлёт уведомлений',
        );
    }
}
