<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\Inventory\NomenclatureItem;

/**
 * Что конкретный пользователь видит в инвентаризации.
 *
 * full — главный администратор, ограничений нет.
 * adminOrgIds — организации (уже вместе с потомками), где он админ инвентаризации: все категории.
 * categoryOrgIds — [id категории => id организаций], где он ответственный только за эту категорию.
 */
final class InventoryScope
{
    /**
     * @param int[]             $adminOrgIds
     * @param array<int, int[]> $categoryOrgIds
     */
    public function __construct(
        public readonly bool $full,
        public readonly array $adminOrgIds = [],
        public readonly array $categoryOrgIds = [],
    ) {
    }

    /**
     * Нет ни одной привязки — пользователь не видит ничего.
     */
    public function isEmpty(): bool
    {
        return !$this->full && $this->adminOrgIds === [] && $this->categoryOrgIds === [];
    }

    /**
     * Может видеть и вести товар: админ организации либо ответственный за его категорию.
     *
     * Товар без категории доступен только админу организации: ответственный отвечает
     * за конкретную категорию, а неразобранный предмет ещё ни к одной не отнесён.
     */
    public function canSee(?int $organizationId, ?int $categoryId): bool
    {
        if ($this->full) {
            return true;
        }

        // Организация не проставлена — позицию видит только главный администратор.
        // Все привязки выданы на конкретные организации, отнести такую строку к чьей-то
        // зоне нельзя: это то же «неразобранное», что и вид без категории.
        if ($organizationId === null) {
            return false;
        }

        if (in_array($organizationId, $this->adminOrgIds, true)) {
            return true;
        }

        if ($categoryId === null) {
            return false;
        }

        return in_array($organizationId, $this->categoryOrgIds[$categoryId] ?? [], true);
    }

    public function canSeeItem(NomenclatureItem $item): bool
    {
        $organizationId = $item->getOrganization()?->getId();
        $categoryId = $item->getCategory()?->getId();

        return $this->canSee(
            $organizationId !== null ? (int) $organizationId : null,
            $categoryId !== null ? (int) $categoryId : null,
        );
    }

    /**
     * Админ организации, а не ответственный за категорию. Только он переносит товар
     * между организациями и меняет категорию — иначе ответственный уводил бы товары
     * из своей зоны ответственности или забирал чужие.
     */
    public function isOrganizationAdmin(int $organizationId): bool
    {
        return $this->full || in_array($organizationId, $this->adminOrgIds, true);
    }

    /**
     * Все организации, доступные пользователю хоть в каком-то виде.
     *
     * @return int[]
     */
    public function allOrganizationIds(): array
    {
        $ids = $this->adminOrgIds;
        foreach ($this->categoryOrgIds as $orgIds) {
            $ids = array_merge($ids, $orgIds);
        }

        return array_values(array_unique($ids));
    }
}
