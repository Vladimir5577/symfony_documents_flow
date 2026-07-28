<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\User\User;
use App\Enum\Inventory\Unit;
use App\Repository\Inventory\NomenclatureRepository;
use App\Repository\Inventory\StockRepository;
use App\Service\SpaApi\Inventory\InventoryAccessService;
use App\Service\SpaApi\Inventory\InventoryApiPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/inventory/stock')]
final class StockController extends AbstractController
{
    /** Допустимые разрезы свёртки (`group_by` контракта). */
    private const GROUP_BY_MODES = ['nomenclature', 'holder', 'room'];

    public function __construct(
        private readonly StockRepository $stocks,
        private readonly NomenclatureRepository $nomenclatures,
        private readonly InventoryAccessService $access,
        private readonly InventoryApiPresenter $presenter,
    ) {
    }

    /**
     * Остатки. Без `group_by` — детальные строки с разрезами managing/room
     * (экран МОЛ/админа/бухгалтерии); с `group_by` — свёртка средствами СУБД.
     * DEPT_VIEWER получает принудительный скоуп своего подразделения.
     */
    #[Route('', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->access->canViewDepartment()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $filters = [];
        foreach (['category_id', 'nomenclature_id', 'holder_user_id', 'warehouse_id', 'managing_warehouse_id', 'room_id', 'department_id'] as $key) {
            if ($request->query->has($key) && $request->query->get($key) !== '') {
                $filters[$key] = (int) $request->query->get($key);
            }
        }
        $filters['search'] = trim((string) $request->query->get('search', ''));

        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->query->get('page_size', 10)));

        // Начальник отдела без общих прав видит только своё подразделение (+потомки)
        if (!$this->access->canViewAll()) {
            $deptIds = $this->access->departmentScopeIds($user);
            if ($deptIds === []) {
                return $this->json(['stock' => [], 'pagination' => $this->presenter->pagination($page, $pageSize, 0)]);
            }
            $filters['department_id'] = $deptIds;
        }

        $groupBy = (string) $request->query->get('group_by', '');
        if (\in_array($groupBy, self::GROUP_BY_MODES, true)) {
            $result = $this->stocks->findAggregated($groupBy, $filters, $page, $pageSize);

            return $this->json([
                'stock' => $this->presentAggregates($result['rows'], $groupBy),
                'pagination' => $this->presenter->pagination($page, $pageSize, $result['total']),
            ]);
        }

        $result = $this->stocks->findFilteredPaginated($filters, $page, $pageSize);

        return $this->json([
            'stock' => array_map(fn ($stock): array => $this->presenter->stockRow($stock), $result['rows']),
            'pagination' => $this->presenter->pagination($page, $pageSize, $result['total']),
        ]);
    }

    /**
     * Строки агрегата → форма InventoryStockRow фронта.
     *
     * Номенклатуры догружаются одним запросом по id: агрегат отдаёт только идентификатор,
     * а карточке нужны единица измерения, категория и признак расходника.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function presentAggregates(array $rows, string $groupBy): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['nomenclatureId'],
            $rows,
        )));
        $nomenclatures = [];
        foreach ($this->nomenclatures->findBy(['id' => $ids]) as $nomenclature) {
            $nomenclatures[(int) $nomenclature->getId()] = $nomenclature;
        }

        $result = [];
        foreach ($rows as $row) {
            $nomenclature = $nomenclatures[(int) $row['nomenclatureId']] ?? null;
            if ($nomenclature === null) {
                continue; // позицию удалили между агрегатом и догрузкой — строке нечего показать
            }

            $item = [
                'nomenclature' => $this->presenter->nomenclature($nomenclature),
                // В разрезе по номенклатуре держателей несколько, одного назвать нельзя —
                // отдаём null, а не выдуманного «первого».
                'holder' => $groupBy === 'holder' ? $this->aggregateHolder($row) : null,
                'quantity' => (string) $row['totalQuantity'],
            ];
            if ($groupBy === 'room') {
                $item['room'] = isset($row['roomId'])
                    ? ['id' => (int) $row['roomId'], 'name' => (string) ($row['roomName'] ?? '')]
                    : null;
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private function aggregateHolder(array $row): ?array
    {
        if (isset($row['holderUserId'])) {
            $name = trim(implode(' ', array_filter([
                $row['holderLastname'] ?? null,
                $row['holderFirstname'] ?? null,
                $row['holderPatronymic'] ?? null,
            ])));

            return ['type' => 'employee', 'id' => (int) $row['holderUserId'], 'name' => $name];
        }

        if (isset($row['holderWarehouseId'])) {
            return [
                'type' => 'warehouse',
                'id' => (int) $row['holderWarehouseId'],
                'name' => (string) ($row['holderWarehouseName'] ?? ''),
            ];
        }

        return null;
    }

    /**
     * «Моё имущество»: свёртка SUM GROUP BY nomenclature (правило свёртки контракта).
     */
    #[Route('/my', methods: ['GET'])]
    public function my(#[CurrentUser] User $user): JsonResponse
    {
        $rows = $this->stocks->findByHolderUser((int) $user->getId());

        return $this->json([
            'stock' => array_map(fn (array $row): array => $this->rollupRow($row, $user), $rows),
        ]);
    }

    /**
     * Остатки подразделения (для DEPT_VIEWER и выше) — детально, но без чужих отделов.
     */
    #[Route('/department', methods: ['GET'])]
    public function department(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->access->canViewDepartment()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }
        $deptIds = $this->access->departmentScopeIds($user);
        if ($deptIds === []) {
            return $this->json(['stock' => [], 'pagination' => $this->presenter->pagination(1, 10, 0)]);
        }

        $rows = array_values(array_filter(
            $this->stocks->findFiltered(['department_id' => $deptIds]),
            static fn ($stock): bool => (float) $stock->getQuantity() > 0,
        ));

        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->query->get('page_size', 10)));

        return $this->json([
            'stock' => array_map(
                fn ($stock): array => $this->presenter->stockRow($stock, rollup: !$this->access->canViewAll()),
                \array_slice($rows, ($page - 1) * $pageSize, $pageSize),
            ),
            'pagination' => $this->presenter->pagination($page, $pageSize, \count($rows)),
        ]);
    }

    /**
     * Строка свёрнутой выборки findByHolderUser → форма InventoryStockRow фронта.
     *
     * @param array{nomenclatureId: int|string, nomenclatureName: string, unit: Unit|string, quantity: string} $row
     *
     * @return array<string, mixed>
     */
    private function rollupRow(array $row, User $user): array
    {
        $unit = $row['unit'] instanceof Unit ? $row['unit'] : Unit::tryFrom((string) $row['unit']);
        $fullName = trim(implode(' ', array_filter([
            $user->getLastname(),
            $user->getFirstname(),
            $user->getPatronymic(),
        ])));

        return [
            'nomenclature' => [
                'id' => (int) $row['nomenclatureId'],
                'name' => $row['nomenclatureName'],
                'category' => null,
                'unit' => [
                    'value' => $unit?->value ?? Unit::PCS->value,
                    'label' => $unit?->getLabel() ?? Unit::PCS->getLabel(),
                ],
                'is_consumable' => false,
                'code_1c' => null,
                'note' => null,
            ],
            'holder' => ['type' => 'employee', 'id' => (int) $user->getId(), 'name' => $fullName],
            'quantity' => (string) $row['quantity'],
        ];
    }
}
