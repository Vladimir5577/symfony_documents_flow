<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Inventory;

use App\Entity\Inventory\Document;
use App\Entity\User\User;
use App\Enum\Inventory\DocumentType;
use App\Enum\User\UserRole;
use App\Repository\Inventory\WarehouseRepository;
use App\Repository\Organization\OrganizationRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Скоупы доступа модуля инвентаризации (матрица — dev_docks/spa_api/inventory/Readme_inventory.md).
 *
 * Контур МОЛа = свои склады (responsible_user_id) + строки остатков/движений сотрудников
 * с managing_warehouse_id из своих складов. Роль ROLE_INVENTORY_MOL — только «включатель»,
 * факт МОЛства определяется складами (решение дебатов).
 *
 * Voter НЕ используется (PermissionVoter в проекте — мёртвый код): #[IsGranted] на контроллерах
 * даёт грубую проверку роли, этот сервис — тонкие скоупы.
 */
final class InventoryAccessService
{
    public function __construct(
        private readonly Security $security,
        private readonly WarehouseRepository $warehouses,
        private readonly OrganizationRepository $organizations,
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->security->isGranted(UserRole::ROLE_INVENTORY_ADMIN->value);
    }

    public function isMol(): bool
    {
        return $this->security->isGranted(UserRole::ROLE_INVENTORY_MOL->value);
    }

    public function canViewAll(): bool
    {
        return $this->isAdmin()
            || $this->isMol()
            || $this->security->isGranted(UserRole::ROLE_INVENTORY_VIEWER->value);
    }

    public function canViewDepartment(): bool
    {
        return $this->canViewAll()
            || $this->security->isGranted(UserRole::ROLE_INVENTORY_DEPT_VIEWER->value);
    }

    /**
     * Склады контура текущего МОЛа (для админа — null = все).
     *
     * @return list<int>|null null — без ограничения; [] — контур пуст (ничего нельзя)
     */
    public function molWarehouseIds(User $user): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }

        return array_values(array_map(
            static fn ($warehouse): int => (int) $warehouse->getId(),
            $this->warehouses->findByResponsible((int) $user->getId()),
        ));
    }

    /**
     * Может ли пользователь создавать/проводить/сторнировать данный документ.
     * Правило: админ — всё; МОЛ — ТОЛЬКО СВОИ документы (created_by; закрывает вакуумное
     * true для документов без строк — находка ревью 2026-07-28) и только в своём контуре
     * (from-склады и from-managing строк принадлежат его складам; перемещение НА чужой
     * склад разрешено — проводит отправитель).
     *
     * Это ранний гейт: ОКОНЧАТЕЛЬНАЯ проверка контура (включая авторезолв from-стороны)
     * выполняется в StockPostingService::assertSpecWithinScope при проведении.
     */
    public function canManageDocument(Document $document, User $user): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        if (!$this->isMol()) {
            return false;
        }
        // Ввод остатков и импорт — только админ
        if ($document->getType() === DocumentType::INITIAL_BALANCE) {
            return false;
        }
        // Не-админ управляет только собственными документами
        $creatorId = $document->getCreatedBy()?->getId();
        if ($creatorId === null || (int) $creatorId !== (int) $user->getId()) {
            return false;
        }

        $ownIds = $this->molWarehouseIds($user) ?? [];
        if ($ownIds === []) {
            return false;
        }

        foreach ($document->getLines() as $line) {
            // Источник обязан принадлежать контуру МОЛа
            $fromWarehouseId = $line->getFromHolderWarehouse()?->getId();
            if ($fromWarehouseId !== null && !\in_array((int) $fromWarehouseId, $ownIds, true)) {
                return false;
            }
            $fromManagingId = $line->getFromManagingWarehouse()?->getId();
            if ($line->getFromHolderUser() !== null && $fromManagingId !== null
                && !\in_array((int) $fromManagingId, $ownIds, true)) {
                return false;
            }
            // Приход (без from) — только на свой склад
            $hasFrom = $line->getFromHolderUser() !== null || $line->getFromHolderWarehouse() !== null;
            $toWarehouseId = $line->getToHolderWarehouse()?->getId();
            if (!$hasFrom && $toWarehouseId !== null && !\in_array((int) $toWarehouseId, $ownIds, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * ID подразделения пользователя + всех потомков (скоуп DEPT_VIEWER).
     * Внимание: findOrganizationWithChildrenIds обходит максимум 5 уровней (ограничение метода).
     *
     * @return list<int>
     */
    public function departmentScopeIds(User $user): array
    {
        $organization = $user->getOrganization();
        if ($organization === null) {
            return [];
        }

        return array_map(
            'intval',
            $this->organizations->findOrganizationWithChildrenIds((int) $organization->getId()),
        );
    }
}
