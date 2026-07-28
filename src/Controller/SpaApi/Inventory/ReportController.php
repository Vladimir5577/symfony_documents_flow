<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\History;
use App\Entity\User\User;
use App\Repository\Inventory\DeviceRepository;
use App\Repository\Inventory\HistoryRepository;
use App\Repository\Inventory\MovementRepository;
use App\Repository\Inventory\NomenclatureRepository;
use App\Repository\Inventory\StockRepository;
use App\Enum\User\UserRole;
use App\Service\SpaApi\Inventory\InventoryAccessService;
use App\Service\SpaApi\Inventory\InventoryApiPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Отчёты и аудит модуля инвентаризации.
 *
 * Класс-гейта по роли нет намеренно: инвентарные роли не наследуют друг друга
 * (`security.yaml`), гейт на классе отрезал бы МОЛа. Доступ решается в каждом методе.
 */
#[Route('/spa/api/inventory')]
final class ReportController extends AbstractController
{
    /** Отчёт без явного периода — за последние 30 дней: типовой запрос бухгалтерии. */
    private const DEFAULT_PERIOD_DAYS = 30;

    public function __construct(
        private readonly StockRepository $stocks,
        private readonly MovementRepository $movements,
        private readonly NomenclatureRepository $nomenclatures,
        private readonly DeviceRepository $devices,
        private readonly HistoryRepository $history,
        private readonly InventoryAccessService $access,
        private readonly InventoryApiPresenter $presenter,
        private readonly Security $security,
    ) {
    }

    /**
     * Остатки в разрезе подразделений: сколько числится за каждым отделом.
     *
     * Считается по держателям остатка (сотрудник → его подразделение, склад → подразделение
     * склада), поэтому цифра отвечает на вопрос «за кем закреплено», а не «где физически лежит».
     */
    #[Route('/reports/stock-by-department', methods: ['GET'])]
    public function stockByDepartment(#[CurrentUser] User $user): JsonResponse
    {
        if (!$this->access->canViewDepartment()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $filters = [];
        if (!$this->access->canViewAll()) {
            $deptIds = $this->access->departmentScopeIds($user);
            if ($deptIds === []) {
                return $this->json(['report' => []]);
            }
            $filters['department_id'] = $deptIds;
        }

        $groups = [];
        foreach ($this->stocks->findFiltered($filters) as $stock) {
            if ($this->toThousandths((string) $stock->getQuantity()) <= 0) {
                continue;
            }

            $department = $stock->getHolderUser()?->getOrganization()
                ?? $stock->getHolderWarehouse()?->getDepartment();
            $key = $department === null ? 0 : (int) $department->getId();

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'department' => $department === null
                        ? null
                        : ['id' => (int) $department->getId(), 'name' => (string) $department->getName()],
                    'positions' => 0,
                    'thousandths' => 0,
                ];
            }
            $groups[$key]['positions']++;
            $groups[$key]['thousandths'] += $this->toThousandths((string) $stock->getQuantity());
        }

        $report = array_values(array_map(
            static fn (array $group): array => [
                'department' => $group['department'],
                'positions' => $group['positions'],
                'quantity' => number_format($group['thousandths'] / 1000, 3, '.', ''),
            ],
            $groups,
        ));
        usort($report, static fn (array $a, array $b): int => $b['positions'] <=> $a['positions']);

        return $this->json(['report' => $report]);
    }

    /**
     * Журнал движений за период. Основной инструмент разбора «куда делось».
     */
    #[Route('/reports/movements', methods: ['GET'])]
    public function movements(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        // По матрице отчёты доступны и начальнику отдела — в границах его подразделения.
        // Раньше здесь стоял canViewAll(), и DEPT_VIEWER получал 403 (находка ревью Codex).
        if (!$this->access->canViewDepartment()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        [$from, $to] = $this->period($request);
        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->query->get('page_size', 50)));

        $requestedWarehouseId = $request->query->has('warehouse_id') ? (int) $request->query->get('warehouse_id') : null;
        $nomenclatureId = $request->query->has('nomenclature_id') ? (int) $request->query->get('nomenclature_id') : null;

        $scopeError = $this->assertWarehouseWithinScope($user, $requestedWarehouseId);
        if ($scopeError !== null) {
            return $scopeError;
        }

        $result = $this->movements->findForPeriod(
            $from,
            $to,
            $this->warehouseScope($user, $requestedWarehouseId),
            $nomenclatureId,
            $page,
            $pageSize,
            $this->departmentScope($user),
        );

        return $this->json([
            'movements' => array_map($this->presenter->movement(...), $result['rows']),
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'pagination' => $this->presenter->pagination($page, $pageSize, $result['total']),
        ]);
    }

    /**
     * Расход материалов за период, свёрнутый по позициям.
     *
     * Смягчает расхождение «выдача = расход» против 1С: показывает, что именно за период
     * ушло в расход и в какие устройства, — из этого собирается акт на списание.
     */
    #[Route('/reports/consumption', methods: ['GET'])]
    public function consumption(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        // Как и журнал движений: начальнику отдела отчёт положен, но в границах отдела.
        if (!$this->access->canViewDepartment()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        [$from, $to] = $this->period($request);
        $deviceId = $request->query->has('device_id') ? (int) $request->query->get('device_id') : null;
        // Разрез по устройствам включается сам, когда спрашивают про конкретное устройство:
        // иначе строки по разным принтерам склеились бы в одну.
        $groupByDevice = $deviceId !== null || $request->query->getBoolean('by_device');

        $rows = $this->movements->consumptionReport(
            $from,
            $to,
            $deviceId,
            $groupByDevice,
            $this->warehouseScope($user, null),
            $this->departmentScope($user),
        );

        $nomenclatureIds = array_values(array_unique(array_column($rows, 'nomenclatureId')));
        $nomenclatures = [];
        foreach ($this->nomenclatures->findBy(['id' => $nomenclatureIds]) as $nomenclature) {
            $nomenclatures[(int) $nomenclature->getId()] = $nomenclature;
        }

        $deviceIds = array_values(array_unique(array_filter(array_column($rows, 'deviceId'))));
        $deviceNames = [];
        foreach ($deviceIds === [] ? [] : $this->devices->findBy(['id' => $deviceIds]) as $device) {
            $deviceNames[(int) $device->getId()] = (string) $device->getName();
        }

        $report = [];
        foreach ($rows as $row) {
            $nomenclature = $nomenclatures[$row['nomenclatureId']] ?? null;
            if ($nomenclature === null) {
                continue;
            }

            $report[] = [
                'nomenclature' => $this->presenter->nomenclature($nomenclature),
                'device' => $row['deviceId'] === null
                    ? null
                    : ['id' => $row['deviceId'], 'name' => $deviceNames[$row['deviceId']] ?? ''],
                'quantity' => $row['quantity'],
                'entries' => $row['entries'],
            ];
        }

        return $this->json([
            'report' => $report,
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        ]);
    }

    /**
     * История изменений сущности — журнал в карточке документа и устройства.
     *
     * ⚠ Секретов в payload нет по построению: InventoryHistoryService вырезает
     * login/password/secret/token перед записью.
     */
    #[Route('/history', methods: ['GET'])]
    public function historyLog(Request $request): JsonResponse
    {
        if (!$this->access->canViewAll()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $entityType = trim((string) $request->query->get('entity_type', ''));
        $entityId = (int) $request->query->get('entity_id', 0);
        if ($entityType === '' || $entityId <= 0) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->query->get('page_size', 50)));
        $criteria = ['entityType' => $entityType, 'entityId' => $entityId];

        $total = $this->history->count($criteria);
        $rows = $this->history->findBy($criteria, ['id' => 'DESC'], $pageSize, ($page - 1) * $pageSize);

        return $this->json([
            'history' => array_map(
                fn (History $entry): array => [
                    'id' => $entry->getId(),
                    'entity_type' => $entry->getEntityType(),
                    'entity_id' => $entry->getEntityId(),
                    'action' => $entry->getAction(),
                    'payload' => $entry->getPayload(),
                    'user' => $entry->getUser() === null ? null : [
                        'id' => $entry->getUser()->getId(),
                        'fullName' => trim(implode(' ', array_filter([
                            $entry->getUser()->getLastname(),
                            $entry->getUser()->getFirstname(),
                            $entry->getUser()->getPatronymic(),
                        ]))),
                    ],
                    'created_at' => $entry->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                ],
                $rows,
            ),
            'pagination' => $this->presenter->pagination($page, $pageSize, $total),
        ]);
    }

    /**
     * Период отчёта. Без параметров — последние 30 дней; перевёрнутый диапазон
     * разворачивается, а не отвергается: опечатка в датах не должна стоить отчёта.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function period(Request $request): array
    {
        $to = $this->parseDate((string) $request->query->get('to', '')) ?? new \DateTimeImmutable('today');
        $from = $this->parseDate((string) $request->query->get('from', ''))
            ?? $to->modify(sprintf('-%d days', self::DEFAULT_PERIOD_DAYS));

        return $from > $to ? [$to, $from] : [$from, $to];
    }

    /**
     * Ограничен ли пользователь контуром МОЛа.
     *
     * ⚠ Только если МОЛ и ПРИ ЭТОМ не админ и не наблюдатель. Роли не наследуют друг друга,
     * поэтому человеку могут выдать МОЛ и наблюдателя одновременно — и тогда действовать
     * должно более широкое право, а не более узкое. Раньше такой пользователь резался
     * как МОЛ (находка ревью Codex).
     */
    private function isMolRestricted(): bool
    {
        return $this->access->isMol()
            && !$this->access->isAdmin()
            && !$this->security->isGranted(UserRole::ROLE_INVENTORY_VIEWER->value);
    }

    /** Отказ, если МОЛ просит чужой склад. */
    private function assertWarehouseWithinScope(User $user, ?int $requestedWarehouseId): ?JsonResponse
    {
        if ($requestedWarehouseId === null || !$this->isMolRestricted()) {
            return null;
        }

        $own = $this->access->molWarehouseIds($user) ?? [];
        if (!\in_array($requestedWarehouseId, $own, true)) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * Склады, которыми ограничена выборка. `null` — без ограничения.
     * Без явного склада МОЛ получает ВСЕ свои склады, а не первый: у завхоза их бывает
     * несколько, и половина журнала молча пропадала бы.
     *
     * @return list<int>|null
     */
    private function warehouseScope(User $user, ?int $requestedWarehouseId): ?array
    {
        if ($requestedWarehouseId !== null) {
            return [$requestedWarehouseId];
        }
        if (!$this->isMolRestricted()) {
            return null;
        }

        return $this->access->molWarehouseIds($user) ?? [];
    }

    /**
     * Подразделения, которыми ограничена выборка (только начальник отдела). `null` — без ограничения.
     *
     * @return list<int>|null
     */
    private function departmentScope(User $user): ?array
    {
        if ($this->access->canViewAll()) {
            return null;
        }

        return $this->access->departmentScopeIds($user);
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($trimmed);
        } catch (\Exception) {
            return null;
        }
    }

    /** Количество в целых тысячных: масштаб схемы DECIMAL(14,3), float здесь запрещён. */
    private function toThousandths(string $quantity): int
    {
        $normalized = trim(str_replace(',', '.', $quantity));

        return $normalized === '' ? 0 : (int) round((float) $normalized * 1000);
    }
}
