<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Repository\Analytics\AnalyticsAggregatedDataRepository;
use App\Repository\Analytics\AnalyticsOrganizationRepository;
use App\Repository\Organization\OrganizationRepository;

/**
 * Формирует данные для дашборда аналитики (вкладки Финансы, ТКО, Кадры).
 * Агрегирует понедельные данные из analytics_aggregated_data в помесячные.
 */
final class DashboardDataService
{
    public const SCALE_MONTH = 'month';
    public const SCALE_WEEK = 'week';

    private const MONTH_LABELS_RU = [
        1 => 'Янв', 2 => 'Фев', 3 => 'Мар', 4 => 'Апр',
        5 => 'Май', 6 => 'Июн', 7 => 'Июл', 8 => 'Авг',
        9 => 'Сен', 10 => 'Окт', 11 => 'Ноя', 12 => 'Дек',
    ];

    private const MONTH_LABELS_RU_FULL = [
        1 => 'Янв', 2 => 'Фев', 3 => 'Мар', 4 => 'Апр',
        5 => 'Май', 6 => 'Июн', 7 => 'Июл', 8 => 'Авг',
        9 => 'Сен', 10 => 'Окт', 11 => 'Ноя', 12 => 'Дек',
    ];

    /** Метрики-потоки: суммируются за месяц */
    private const SUM_KEYS = [
        'cash_inflow',
        'cash_outflow',
        'fuel_consumption',
        'tko_export',
        'employees_hired',
        'employees_terminated',
    ];

    /** Метрики-остатки: берётся среднее за месяц */
    private const AVG_KEYS = [
        'account_balance',
        'staff_planned_count',
        'staff_actual_count',
        'accounts_receivable_total',
        'accounts_receivable_population_tko_export',
        'accounts_receivable_legal_entities_tko',
        'accounts_payable_total',
        'accounts_payable_contractors_tko_export',
        'accounts_payable_landfills_maintenance',
        'accounts_payable_fuel',
        'accounts_payable_other_goods_services',
        'bank_balance_total',
        'bank_balance_main_account',
        'bank_balance_yasinovataya_unit',
        'bank_balance_card_account',
        'bank_balance_head_opened_for_branches',
        'bank_balance_landfills',
        'bank_balance_road_service',
        'bank_balance_branch_opened_accounts_total',
        'bank_balance_donetsk_branch',
        'bank_balance_mariupol_branch',
        'bank_balance_makeevka_branch',
        'bank_balance_shakhtyorsk_branch',
        'bank_balance_gorlovka_branch',
        'bank_balance_yenakiieve_branch',
        'bank_balance_amvrosiivka_branch',
    ];

    /** Все бизнес-ключи */
    private const ALL_KEYS = [
        'cash_inflow', 'cash_outflow', 'account_balance',
        'fuel_consumption', 'tko_export',
        'employees_hired', 'employees_terminated',
        'staff_planned_count', 'staff_actual_count',
        'accounts_receivable_total',
        'accounts_receivable_population_tko_export',
        'accounts_receivable_legal_entities_tko',
        'accounts_payable_total',
        'accounts_payable_contractors_tko_export',
        'accounts_payable_landfills_maintenance',
        'accounts_payable_fuel',
        'accounts_payable_other_goods_services',
        'bank_balance_total',
        'bank_balance_main_account',
        'bank_balance_yasinovataya_unit',
        'bank_balance_card_account',
        'bank_balance_head_opened_for_branches',
        'bank_balance_landfills',
        'bank_balance_road_service',
        'bank_balance_branch_opened_accounts_total',
        'bank_balance_donetsk_branch',
        'bank_balance_mariupol_branch',
        'bank_balance_makeevka_branch',
        'bank_balance_shakhtyorsk_branch',
        'bank_balance_gorlovka_branch',
        'bank_balance_yenakiieve_branch',
        'bank_balance_amvrosiivka_branch',
    ];

    /** Финансовые ключи, значения которых нужно перевести в миллионы */
    private const FINANCE_KEYS = ['cash_inflow', 'cash_outflow', 'account_balance'];

    public function __construct(
        private readonly AnalyticsAggregatedDataRepository $aggregatedDataRepo,
        private readonly AnalyticsOrganizationRepository $analyticsOrgRepo,
        private readonly OrganizationRepository $organizationRepo,
    ) {
    }

    /**
     * Получить данные дашборда для организации.
     *
     * @param int $organizationId ID организации (0 = все родительские)
     * @return array<string, mixed>
     */
    public function getData(int $organizationId, string $scale = self::SCALE_MONTH): array
    {
        $orgIds = $this->resolveOrganizationIds($organizationId);

        if (empty($orgIds)) {
            return $this->emptyData($scale);
        }

        $rows = $scale === self::SCALE_WEEK
            ? $this->aggregatedDataRepo->findWeeklyAggregated($orgIds, self::ALL_KEYS)
            : $this->aggregatedDataRepo->findMonthlyAggregated($orgIds, self::ALL_KEYS);

        return $this->buildResponse($rows, $scale);
    }

    /**
     * Данные для таблицы сравнения организаций за конкретный период.
     * Всегда показывает все родительские организации (те же что карточки сверху).
     * Параметр $organizationId игнорируется — таб сравнения независим от выбора.
     *
     * @return array{scale: string, selectedYear: int, selectedPeriod: int, availablePeriods: array, rows: array}
     */
    public function getCompareData(int $organizationId, string $scale = self::SCALE_MONTH, ?int $year = null, ?int $period = null): array
    {
        // Берём организации из таблицы настроек дашборда
        $analyticsOrgs = $this->analyticsOrgRepo->findVisibleOrdered();

        if (empty($analyticsOrgs)) {
            return $this->emptyCompareData($scale);
        }

        // Для каждой организации собираем все её ID (с дочерними)
        // и строим маппинг descendantId => parentOrgId
        $orgMap = []; // parentOrgId => ['name' => ..., 'allIds' => [...]]
        $descendantToParent = []; // anyDescendantId => parentOrgId
        $allOrgIds = [];

        foreach ($analyticsOrgs as $ao) {
            $org = $ao->getOrganization();
            $parentId = $org->getId();
            $descIds = $this->organizationRepo->findOrganizationWithChildrenIds($parentId);
            $orgMap[$parentId] = [
                'name' => $org->getFullName() ?: $org->getName(),
                'allIds' => $descIds,
            ];
            foreach ($descIds as $dId) {
                $descendantToParent[$dId] = $parentId;
            }
            $allOrgIds = array_merge($allOrgIds, $descIds);
        }

        $allOrgIds = array_values(array_unique($allOrgIds));

        // Доступные периоды
        $availablePeriods = $scale === self::SCALE_WEEK
            ? $this->aggregatedDataRepo->findAvailableWeeks($allOrgIds)
            : $this->aggregatedDataRepo->findAvailableMonths($allOrgIds);

        if (empty($availablePeriods)) {
            return $this->emptyCompareData($scale);
        }

        // Если период не задан — последний доступный
        if ($year === null || $period === null) {
            $latest = $availablePeriods[0];
            $year = (int) $latest['yr'];
            $period = (int) ($scale === self::SCALE_WEEK ? $latest['wk'] : $latest['mo']);
        }

        // Метки для селектора
        $periodsForSelect = [];
        foreach ($availablePeriods as $ap) {
            if ($scale === self::SCALE_WEEK) {
                $periodsForSelect[] = [
                    'year' => (int) $ap['yr'],
                    'period' => (int) $ap['wk'],
                    'label' => self::weekRangeLabel((int) $ap['yr'], (int) $ap['wk'], true),
                ];
            } else {
                $periodsForSelect[] = [
                    'year' => (int) $ap['yr'],
                    'period' => (int) $ap['mo'],
                    'label' => self::MONTH_LABELS_RU_FULL[(int) $ap['mo']] . ' ' . $ap['yr'],
                ];
            }
        }

        // Данные с разбивкой по organization_id
        $rows = $scale === self::SCALE_WEEK
            ? $this->aggregatedDataRepo->findCompareWeekly($allOrgIds, self::ALL_KEYS, $year, $period)
            : $this->aggregatedDataRepo->findCompareMonthly($allOrgIds, self::ALL_KEYS, $year, $period);

        // Агрегируем по родительским организациям
        $orgData = []; // parentOrgId => [businessKey => value]
        foreach ($rows as $row) {
            $oid = (int) $row['organization_id'];
            $parentId = $descendantToParent[$oid] ?? null;
            if ($parentId === null) {
                continue;
            }
            $bk = $row['business_key'];
            $val = in_array($bk, self::SUM_KEYS, true)
                ? (float) ($row['total_value'] ?? 0)
                : (float) ($row['avg_value'] ?? 0);

            if (!isset($orgData[$parentId][$bk])) {
                $orgData[$parentId][$bk] = 0.0;
            }
            $orgData[$parentId][$bk] += $val;
        }

        // Формируем строки
        $resultRows = [];
        $totals = array_fill_keys(self::ALL_KEYS, 0.0);

        foreach ($orgMap as $parentId => $info) {
            $data = $orgData[$parentId] ?? [];
            $resultRows[] = $this->buildCompareRow($info['name'], $data, false);

            foreach (self::ALL_KEYS as $k) {
                $totals[$k] += $data[$k] ?? 0.0;
            }
        }

        // Строка итого — первая
        $totalRow = $this->buildCompareRow('Итого', $totals, true);
        array_unshift($resultRows, $totalRow);

        return [
            'scale' => $scale,
            'selectedYear' => $year,
            'selectedPeriod' => $period,
            'availablePeriods' => $periodsForSelect,
            'rows' => $resultRows,
        ];
    }

    /**
     * @param array<string, float> $data
     * @return array<string, mixed>
     */
    private function buildCompareRow(string $name, array $data, bool $isTotal): array
    {
        return [
            'name' => $name,
            'isTotal' => $isTotal,
            'cashInflow' => round(($data['cash_inflow'] ?? 0) / 1_000_000, 1),
            'cashOutflow' => round(($data['cash_outflow'] ?? 0) / 1_000_000, 1),
            'tkoExport' => (int) round($data['tko_export'] ?? 0),
            'fuelConsumption' => round(($data['fuel_consumption'] ?? 0) / 1_000_000, 1),
            'staffPlanned' => (int) round($data['staff_planned_count'] ?? 0),
            'staffActual' => (int) round($data['staff_actual_count'] ?? 0),
            'hired' => (int) round($data['employees_hired'] ?? 0),
            'terminated' => (int) round($data['employees_terminated'] ?? 0),
        ];
    }

    /**
     * @return array{scale: string, selectedYear: int, selectedPeriod: int, availablePeriods: array, rows: array}
     */
    private function emptyCompareData(string $scale): array
    {
        return [
            'scale' => $scale,
            'selectedYear' => 0,
            'selectedPeriod' => 0,
            'availablePeriods' => [],
            'rows' => [],
        ];
    }

    /**
     * Получить ID организации и всех дочерних.
     * Если $orgId = 0, собрать все родительские организации и их детей.
     *
     * @return int[]
     */
    private function resolveOrganizationIds(int $orgId): array
    {
        if ($orgId === 0) {
            // Все родительские организации
            $parents = $this->organizationRepo->findAllParentOrganizations();
            $ids = [];
            foreach ($parents as $parent) {
                $ids = array_merge(
                    $ids,
                    $this->organizationRepo->findOrganizationWithChildrenIds($parent->getId())
                );
            }

            return array_unique($ids);
        }

        return $this->organizationRepo->findOrganizationWithChildrenIds($orgId);
    }

    /**
     * Собрать ответ из сырых SQL-строк.
     *
     * @param array<int, array<string, int|string|null>> $rows
     * @return array<string, mixed>
     */
    private function buildResponse(array $rows, string $scale): array
    {
        // Собираем уникальные ключи периодов для оси X
        $periodKeys = [];
        foreach ($rows as $row) {
            if ($scale === self::SCALE_WEEK) {
                $key = $row['yr'] . '-W' . str_pad((string) $row['wk'], 2, '0', STR_PAD_LEFT);
                $periodKeys[$key] = (int) $row['wk'];
                continue;
            }

            $key = $row['yr'] . '-' . str_pad((string) $row['mo'], 2, '0', STR_PAD_LEFT);
            $periodKeys[$key] = (int) $row['mo'];
        }
        ksort($periodKeys); // хронологический порядок

        $labels = [];
        $periodOrder = []; // key => index
        $idx = 0;

        $years = array_unique(array_map(static fn(string $k) => substr($k, 0, 4), array_keys($periodKeys)));
        $multiYear = count($years) > 1;

        foreach ($periodKeys as $key => $value) {
            $yr = (int) substr($key, 0, 4);
            if ($scale === self::SCALE_WEEK) {
                $label = self::weekRangeLabel($yr, $value, $multiYear);
            } else {
                $label = self::MONTH_LABELS_RU[$value];
                if ($multiYear) {
                    $label .= " '" . substr((string) $yr, 2);
                }
            }
            $labels[] = $label;
            $periodOrder[$key] = $idx++;
        }

        $totalPoints = count($labels);

        // Индексируем данные: [businessKey][periodKey] => value
        $indexed = [];
        foreach ($rows as $row) {
            $key = $scale === self::SCALE_WEEK
                ? $row['yr'] . '-W' . str_pad((string) $row['wk'], 2, '0', STR_PAD_LEFT)
                : $row['yr'] . '-' . str_pad((string) $row['mo'], 2, '0', STR_PAD_LEFT);
            $bk = $row['business_key'];

            if (in_array($bk, self::SUM_KEYS, true)) {
                $indexed[$bk][$key] = (float) ($row['total_value'] ?? 0);
            } else {
                $indexed[$bk][$key] = (float) ($row['avg_value'] ?? 0);
            }
        }

        // Заполняем массивы для каждой метрики (с нулями для пропущенных периодов)
        $series = [];
        foreach (self::ALL_KEYS as $bk) {
            $arr = array_fill(0, $totalPoints, 0.0);
            if (isset($indexed[$bk])) {
                foreach ($indexed[$bk] as $periodKey => $val) {
                    if (isset($periodOrder[$periodKey])) {
                        $arr[$periodOrder[$periodKey]] = $val;
                    }
                }
            }
            $series[$bk] = $arr;
        }

        // Финансовые значения в миллионы
        foreach (self::FINANCE_KEYS as $fk) {
            $series[$fk] = array_map(static fn(float $v): float => round($v / 1_000_000, 1), $series[$fk]);
        }

        // HR: staff_planned и staff_actual — последнее ненулевое значение
        $staffPlanned = $this->lastNonZero($series['staff_planned_count']);
        $staffActual = $this->lastNonZero($series['staff_actual_count']);
        $vacancies = max(0, (int) round($staffPlanned - $staffActual));

        // Hired/terminated — округляем до целых
        $series['employees_hired'] = array_map(static fn(float $v): int => (int) round($v), $series['employees_hired']);
        $series['employees_terminated'] = array_map(static fn(float $v): int => (int) round($v), $series['employees_terminated']);

        // TKO — округляем до целых
        $series['tko_export'] = array_map(static fn(float $v): int => (int) round($v), $series['tko_export']);

        // Финансовый snapshot по последнему периоду
        $receivableTotal = $this->lastValue($series['accounts_receivable_total']);
        $payableTotal = $this->lastValue($series['accounts_payable_total']);
        $balanceTotal = $this->lastValue($series['bank_balance_total']);
        $balanceCoveragePct = $payableTotal > 0 ? round(($balanceTotal / $payableTotal) * 100, 1) : 0.0;
        $debtorCreditorRatio = $payableTotal > 0 ? round($receivableTotal / $payableTotal, 2) : 0.0;
        $netPosition = round($balanceTotal - $payableTotal, 2);

        return [
            'scale' => $scale,
            'labels' => $labels,
            'finance' => [
                'cashInflow' => $series['cash_inflow'],
                'cashOutflow' => $series['cash_outflow'],
                'trends' => [
                    'receivableTotal' => $series['accounts_receivable_total'],
                    'payableTotal' => $series['accounts_payable_total'],
                    'balanceTotal' => $series['bank_balance_total'],
                ],
                'kpis' => [
                    'receivableTotal' => round($receivableTotal, 2),
                    'payableTotal' => round($payableTotal, 2),
                    'balanceTotal' => round($balanceTotal, 2),
                    'debtorCreditorRatio' => $debtorCreditorRatio,
                    'balanceCoveragePct' => $balanceCoveragePct,
                    'netPosition' => $netPosition,
                ],
                'receivablesBreakdown' => [
                    'populationTkoExport' => round($this->lastValue($series['accounts_receivable_population_tko_export']), 2),
                    'legalEntitiesTko' => round($this->lastValue($series['accounts_receivable_legal_entities_tko']), 2),
                ],
                'receivablesBreakdownSeries' => [
                    'populationTkoExport' => $series['accounts_receivable_population_tko_export'],
                    'legalEntitiesTko' => $series['accounts_receivable_legal_entities_tko'],
                ],
                'payablesBreakdown' => [
                    'contractorsTkoExport' => round($this->lastValue($series['accounts_payable_contractors_tko_export']), 2),
                    'landfillsMaintenance' => round($this->lastValue($series['accounts_payable_landfills_maintenance']), 2),
                    'fuel' => round($this->lastValue($series['accounts_payable_fuel']), 2),
                    'otherGoodsServices' => round($this->lastValue($series['accounts_payable_other_goods_services']), 2),
                ],
                'payablesBreakdownSeries' => [
                    'contractorsTkoExport' => $series['accounts_payable_contractors_tko_export'],
                    'landfillsMaintenance' => $series['accounts_payable_landfills_maintenance'],
                    'fuel' => $series['accounts_payable_fuel'],
                    'otherGoodsServices' => $series['accounts_payable_other_goods_services'],
                ],
                'balancesBreakdown' => [
                    'mainAccount' => round($this->lastValue($series['bank_balance_main_account']), 2),
                    'yasinovatayaUnit' => round($this->lastValue($series['bank_balance_yasinovataya_unit']), 2),
                    'cardAccount' => round($this->lastValue($series['bank_balance_card_account']), 2),
                    'headOpenedForBranches' => round($this->lastValue($series['bank_balance_head_opened_for_branches']), 2),
                    'landfills' => round($this->lastValue($series['bank_balance_landfills']), 2),
                    'roadService' => round($this->lastValue($series['bank_balance_road_service']), 2),
                    'branchOpenedAccountsTotal' => round($this->lastValue($series['bank_balance_branch_opened_accounts_total']), 2),
                ],
                'branchesBreakdown' => [
                    ['name' => 'Донецкий филиал', 'value' => round($this->lastValue($series['bank_balance_donetsk_branch']), 2)],
                    ['name' => 'Мариупольский филиал', 'value' => round($this->lastValue($series['bank_balance_mariupol_branch']), 2)],
                    ['name' => 'Макеевский филиал', 'value' => round($this->lastValue($series['bank_balance_makeevka_branch']), 2)],
                    ['name' => 'Шахтерский филиал', 'value' => round($this->lastValue($series['bank_balance_shakhtyorsk_branch']), 2)],
                    ['name' => 'Горловский филиал', 'value' => round($this->lastValue($series['bank_balance_gorlovka_branch']), 2)],
                    ['name' => 'Енакиевский филиал', 'value' => round($this->lastValue($series['bank_balance_yenakiieve_branch']), 2)],
                    ['name' => 'Амвросиевский филиал', 'value' => round($this->lastValue($series['bank_balance_amvrosiivka_branch']), 2)],
                ],
                'branchesBreakdownSeries' => [
                    ['name' => 'Донецкий филиал', 'values' => $series['bank_balance_donetsk_branch']],
                    ['name' => 'Мариупольский филиал', 'values' => $series['bank_balance_mariupol_branch']],
                    ['name' => 'Макеевский филиал', 'values' => $series['bank_balance_makeevka_branch']],
                    ['name' => 'Шахтерский филиал', 'values' => $series['bank_balance_shakhtyorsk_branch']],
                    ['name' => 'Горловский филиал', 'values' => $series['bank_balance_gorlovka_branch']],
                    ['name' => 'Енакиевский филиал', 'values' => $series['bank_balance_yenakiieve_branch']],
                    ['name' => 'Амвросиевский филиал', 'values' => $series['bank_balance_amvrosiivka_branch']],
                ],
            ],
            'tko' => [
                'tkoExport' => $series['tko_export'],
                'fuelConsumption' => $series['fuel_consumption'],
            ],
            'hr' => [
                'hired' => $series['employees_hired'],
                'terminated' => $series['employees_terminated'],
                'staffPlanned' => (int) round($staffPlanned),
                'staffActual' => (int) round($staffActual),
                'vacancies' => $vacancies,
            ],
        ];
    }

    /**
     * Метка недели в формате диапазона дат: "07.04–13.04" или "07.04–13.04'26" (для мультигода).
     */
    private static function weekRangeLabel(int $year, int $week, bool $multiYear): string
    {
        $monday = new \DateTime();
        $monday->setISODate($year, $week, 1); // понедельник
        $sunday = clone $monday;
        $sunday->modify('+6 days'); // воскресенье

        $label = $monday->format('d.m') . '–' . $sunday->format('d.m');
        if ($multiYear) {
            $label .= "'" . substr((string) $year, 2);
        }

        return $label;
    }

    private function lastNonZero(array $arr): float
    {
        for ($i = count($arr) - 1; $i >= 0; $i--) {
            if ($arr[$i] > 0) {
                return $arr[$i];
            }
        }

        return 0.0;
    }

    private function lastValue(array $arr): float
    {
        if (empty($arr)) {
            return 0.0;
        }

        return (float) ($arr[array_key_last($arr)] ?? 0.0);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyData(string $scale): array
    {
        return [
            'scale' => $scale,
            'labels' => [],
            'finance' => [
                'cashInflow' => [],
                'cashOutflow' => [],
                'trends' => [
                    'receivableTotal' => [],
                    'payableTotal' => [],
                    'balanceTotal' => [],
                ],
                'kpis' => [
                    'receivableTotal' => 0,
                    'payableTotal' => 0,
                    'balanceTotal' => 0,
                    'debtorCreditorRatio' => 0,
                    'balanceCoveragePct' => 0,
                    'netPosition' => 0,
                ],
                'receivablesBreakdown' => [
                    'populationTkoExport' => 0,
                    'legalEntitiesTko' => 0,
                ],
                'receivablesBreakdownSeries' => [
                    'populationTkoExport' => [],
                    'legalEntitiesTko' => [],
                ],
                'payablesBreakdown' => [
                    'contractorsTkoExport' => 0,
                    'landfillsMaintenance' => 0,
                    'fuel' => 0,
                    'otherGoodsServices' => 0,
                ],
                'payablesBreakdownSeries' => [
                    'contractorsTkoExport' => [],
                    'landfillsMaintenance' => [],
                    'fuel' => [],
                    'otherGoodsServices' => [],
                ],
                'balancesBreakdown' => [
                    'mainAccount' => 0,
                    'yasinovatayaUnit' => 0,
                    'cardAccount' => 0,
                    'headOpenedForBranches' => 0,
                    'landfills' => 0,
                    'roadService' => 0,
                    'branchOpenedAccountsTotal' => 0,
                ],
                'branchesBreakdown' => [],
                'branchesBreakdownSeries' => [],
            ],
            'tko' => [
                'tkoExport' => [],
                'fuelConsumption' => [],
            ],
            'hr' => [
                'hired' => [],
                'terminated' => [],
                'staffPlanned' => 0,
                'staffActual' => 0,
                'vacancies' => 0,
            ],
        ];
    }
}
