<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Repository\Analytics\AnalyticsAggregatedDataRepository;
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
    ];

    /** Все бизнес-ключи */
    private const ALL_KEYS = [
        'cash_inflow', 'cash_outflow', 'account_balance',
        'fuel_consumption', 'tko_export',
        'employees_hired', 'employees_terminated',
        'staff_planned_count', 'staff_actual_count',
    ];

    /** Финансовые ключи, значения которых нужно перевести в миллионы */
    private const FINANCE_KEYS = ['cash_inflow', 'cash_outflow', 'account_balance'];

    public function __construct(
        private readonly AnalyticsAggregatedDataRepository $aggregatedDataRepo,
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
                $label = 'W' . str_pad((string) $value, 2, '0', STR_PAD_LEFT);
                if ($multiYear) {
                    $label .= " '" . substr((string) $yr, 2);
                }
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

        return [
            'scale' => $scale,
            'labels' => $labels,
            'finance' => [
                'cashInflow' => $series['cash_inflow'],
                'cashOutflow' => $series['cash_outflow'],
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

    private function lastNonZero(array $arr): float
    {
        for ($i = count($arr) - 1; $i >= 0; $i--) {
            if ($arr[$i] > 0) {
                return $arr[$i];
            }
        }

        return 0.0;
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
