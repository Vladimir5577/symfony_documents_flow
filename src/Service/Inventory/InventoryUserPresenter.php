<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\User\User;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Единственное место, где экраны инвентаризации решают, что показывать про
 * человека.
 *
 * Логин — учётные данные, а не способ представиться: по нему подбирают пароль,
 * и знать его посторонним незачем. На экранах инвентаризации человек нужен
 * только для того, чтобы понять, за кем числится имущество, а для этого хватает
 * ФИО. Поэтому логин отдаётся исключительно администратору инвентаризации —
 * решение владельца от 2026-08-09.
 *
 * Граница здесь своя, отличная от каталога сотрудников (там ROLE_MANAGER):
 * ROLE_INVENTORY_MANAGER выдаётся широко — им помечают ответственных за
 * категорию в каждой организации, — и приравнивать его к кадровому доступу
 * нельзя.
 *
 * Ключ login из ответа не исчезает, а становится null: фронт объявляет его
 * как `string | null` и подставляет вместо отсутствующего ФИО, поэтому
 * пропажа ключа сломала бы разбор ответа, а null он обрабатывает.
 */
final readonly class InventoryUserPresenter
{
    public function __construct(private AuthorizationCheckerInterface $security)
    {
    }

    public function canSeeLogin(): bool
    {
        return $this->security->isGranted('ROLE_INVENTORY_ADMIN');
    }

    /**
     * Человек с ФИО: списки имущества, привязки доступа, справочник.
     *
     * @return array<string, mixed>|null
     */
    public function format(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->getId(),
            'lastname' => $user->getLastname() ?? '-',
            'firstname' => $user->getFirstname() ?? '-',
            'patronymic' => $user->getPatronymic() ?? '-',
            'login' => $this->canSeeLogin() ? $user->getLogin() : null,
        ];
    }

    /**
     * Автор записи: кто создал привязку, документ или загрузил файл.
     *
     * Раньше здесь отдавались только id и логин, то есть для не-администратора
     * не осталось бы ничего читаемого. Поэтому добавлено ФИО: интерфейсу есть
     * что показать, а логин по-прежнему виден только администратору.
     *
     * @return array<string, mixed>|null
     */
    public function formatAuthor(?User $user): ?array
    {
        return $this->format($user);
    }

    /**
     * Подпись для выгрузки: ФИО, а если его нет — прочерк.
     *
     * Логин сюда не подставляется даже как запасной вариант: выгрузку делает
     * менеджер, и одна строка без ФИО не повод показать ему учётную запись.
     */
    public function displayName(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $parts = array_filter([$user->getLastname(), $user->getFirstname(), $user->getPatronymic()]);
        if ($parts !== []) {
            return implode(' ', $parts);
        }

        return $this->canSeeLogin() ? $user->getLogin() : '-';
    }
}
