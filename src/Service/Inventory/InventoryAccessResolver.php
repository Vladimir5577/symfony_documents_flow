<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\Inventory\InventoryAccess;
use App\Entity\User\User;
use App\Repository\Inventory\InventoryAccessRepository;
use App\Repository\Organization\OrganizationRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Собирает скоуп текущего пользователя из его привязок inventory_access.
 *
 * Полный доступ — только явная роль ROLE_INVENTORY_ADMIN (её же получает ROLE_ADMIN
 * через иерархию). У остальных отсутствие привязок означает «не видит ничего», а не
 * «видит всё»: иначе забытая привязка молча выдавала бы полный доступ.
 */
final class InventoryAccessResolver
{
    public function __construct(
        private readonly InventoryAccessRepository $accessRepository,
        private readonly OrganizationRepository $organizationRepository,
        private readonly Security $security,
    ) {
    }

    /**
     * Скоуп считается один раз за запрос: контроллеры спрашивают его по нескольку раз,
     * а каждый расчёт — это выборка привязок плюс обход дерева организаций.
     * Привязки в пределах запроса меняются только после того, как скоуп уже проверен.
     */
    private ?InventoryScope $currentScope = null;

    public function resolveCurrent(): InventoryScope
    {
        if ($this->currentScope !== null) {
            return $this->currentScope;
        }

        // Полный доступ — у главного администратора инвентаризации; ROLE_ADMIN
        // получает его через иерархию ролей. Привязки таким людям не нужны.
        if ($this->security->isGranted('ROLE_INVENTORY_ADMIN')) {
            return $this->currentScope = new InventoryScope(true);
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return $this->currentScope = new InventoryScope(false);
        }

        return $this->currentScope = $this->resolveFor($user);
    }

    public function resolveFor(User $user): InventoryScope
    {
        $adminOrgIds = [];
        $categoryOrgIds = [];

        // ponytail: поддерево тянется рекурсивной загрузкой сущностей (готовый хелпер репозитория),
        // с кэшем на вызов. Если дерево организаций разрастётся — заменить на рекурсивный CTE.
        $subtreeCache = [];

        foreach ($this->accessRepository->findByUser($user) as $access) {
            $organizationId = (int) $access->getOrganization()->getId();
            $subtreeCache[$organizationId] ??= $this->organizationRepository
                ->findOrganizationWithChildrenIds($organizationId);
            $subtree = $subtreeCache[$organizationId];

            if ($access->isOrganizationAdmin()) {
                $adminOrgIds = array_merge($adminOrgIds, $subtree);

                continue;
            }

            $categoryId = (int) $access->getCategory()->getId();
            $categoryOrgIds[$categoryId] = array_merge($categoryOrgIds[$categoryId] ?? [], $subtree);
        }

        $adminOrgIds = array_values(array_unique($adminOrgIds));
        foreach ($categoryOrgIds as $categoryId => $orgIds) {
            $categoryOrgIds[$categoryId] = array_values(array_unique($orgIds));
        }

        return new InventoryScope(false, $adminOrgIds, $categoryOrgIds);
    }

    /**
     * Может ли текущий пользователь выдавать и отзывать привязки в этой организации.
     * Главный администратор — где угодно, админ организации — в своём поддереве.
     */
    public function canManageAccessIn(int $organizationId): bool
    {
        return $this->resolveCurrent()->isOrganizationAdmin($organizationId);
    }

    /**
     * Привязка уже существует — чтобы не ловить нарушение уникального индекса.
     */
    public function findExisting(User $user, int $organizationId, ?int $categoryId): ?InventoryAccess
    {
        foreach ($this->accessRepository->findByUser($user) as $access) {
            $sameOrganization = (int) $access->getOrganization()->getId() === $organizationId;
            $sameCategory = $categoryId === null
                ? $access->getCategory() === null
                : (int) $access->getCategory()?->getId() === $categoryId;

            if ($sameOrganization && $sameCategory) {
                return $access;
            }
        }

        return null;
    }
}
