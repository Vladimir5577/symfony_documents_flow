<?php

declare(strict_types=1);

namespace App\Tests\Service\Inventory;

use App\Entity\User\User;
use App\Service\Inventory\InventoryUserPresenter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Правило «логин видит только администратор инвентаризации» живёт в одном месте,
 * и проверять его надо там же.
 *
 * Функциональный тест ходит только в справочник сотрудников; остальные четыре
 * экрана используют этот же презентер, и если правило сломается в нём, ответы
 * испортятся везде сразу. Поэтому здесь проверяется сам презентер, включая
 * случаи, до которых через эндпоинт добираться дорого: человек без ФИО и
 * отсутствующий человек.
 */
final class InventoryUserPresenterTest extends TestCase
{
    public function testManagerDoesNotSeeLogin(): void
    {
        $presenter = $this->presenterFor(false);

        $formatted = $presenter->format($this->user('ivanov', 'Иванов', 'Иван'));

        self::assertArrayHasKey('login', $formatted, 'ключ должен остаться: фронт объявляет его как string | null');
        self::assertNull($formatted['login']);
        self::assertSame('Иванов', $formatted['lastname']);
        self::assertSame('Иван', $formatted['firstname']);
    }

    public function testAdminSeesLogin(): void
    {
        $presenter = $this->presenterFor(true);

        $formatted = $presenter->format($this->user('ivanov', 'Иванов', 'Иван'));

        self::assertSame('ivanov', $formatted['login']);
    }

    public function testAuthorIsFormattedTheSameWay(): void
    {
        $presenter = $this->presenterFor(false);

        // Автор записи раньше отдавался как id + логин, то есть не-администратор
        // не увидел бы ничего читаемого.
        $formatted = $presenter->formatAuthor($this->user('petrov', 'Петров', 'Пётр'));

        self::assertSame('Петров', $formatted['lastname']);
        self::assertNull($formatted['login']);
    }

    public function testMissingUserStaysNull(): void
    {
        $presenter = $this->presenterFor(true);

        self::assertNull($presenter->format(null));
        self::assertNull($presenter->formatAuthor(null));
        self::assertNull($presenter->displayName(null));
    }

    public function testUserWithoutNameIsShownWithDashesInsteadOfLogin(): void
    {
        $presenter = $this->presenterFor(false);
        $user = $this->user('secret.login', null, null);

        self::assertSame('-', $presenter->format($user)['lastname']);
        self::assertNull($presenter->format($user)['login']);
        self::assertSame(
            '-',
            $presenter->displayName($user),
            'в выгрузке логин не подставляется вместо пустого ФИО даже как запасной вариант',
        );
    }

    public function testDisplayNameFallsBackToLoginOnlyForAdmin(): void
    {
        $presenter = $this->presenterFor(true);

        self::assertSame('secret.login', $presenter->displayName($this->user('secret.login', null, null)));
        self::assertSame('Сидоров Сидор', $presenter->displayName($this->user('sidorov', 'Сидоров', 'Сидор')));
    }

    private function presenterFor(bool $isInventoryAdmin): InventoryUserPresenter
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => $attribute === 'ROLE_INVENTORY_ADMIN' && $isInventoryAdmin,
        );

        return new InventoryUserPresenter($checker);
    }

    private function user(string $login, ?string $lastname, ?string $firstname): User
    {
        $user = new User();
        $user->setLogin($login);
        $user->setLastname($lastname);
        $user->setFirstname($firstname);

        return $user;
    }
}
