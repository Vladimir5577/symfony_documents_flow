<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Enum\Inventory\ItemStatus;
use App\Repository\Organization\OrganizationRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Разбор query-параметров списка товаров.
 *
 * Вынесено из контроллера, потому что теми же фильтрами пользуются список и выгрузка
 * в xlsx. Если бы разбор был скопирован в два места, он однажды разъехался бы, и
 * выгрузка отдавала бы не тот набор строк, который человек видит на экране.
 */
final class ItemFilterFactory
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function fromRequest(Request $request): array
    {
        return [
            'organizationIds' => $this->resolveOrganizationIds($request),
            'categoryId' => $request->query->getInt('category_id') ?: null,
            'nomenclatureId' => $request->query->getInt('nomenclature_id') ?: null,
            'updId' => $request->query->getInt('upd_id') ?: null,
            'noCategory' => $request->query->getBoolean('no_category'),
            'status' => ItemStatus::tryFrom((string) $request->query->get('status', '')),
            'assignedToId' => $request->query->getInt('assigned_to_id') ?: null,
            'unassigned' => $request->query->getBoolean('unassigned'),
            'assigneeFired' => $request->query->getBoolean('assignee_fired'),
            'search' => (string) $request->query->get('search', ''),
            'sort' => (string) $request->query->get('sort', ''),
            'direction' => (string) $request->query->get('direction', ''),
        ];
    }

    /**
     * По умолчанию организация берётся вместе с потомками: у головной организации
     * имущество обычно лежит по департаментам, и список без них выглядел бы пустым.
     *
     * @return int[]|null
     */
    private function resolveOrganizationIds(Request $request): ?array
    {
        $organizationId = $request->query->getInt('organization_id');
        if ($organizationId <= 0) {
            return null;
        }

        if ($request->query->getBoolean('only_own_organization')) {
            return [$organizationId];
        }

        return $this->organizationRepository->findOrganizationWithChildrenIds($organizationId);
    }
}
