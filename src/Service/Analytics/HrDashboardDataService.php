<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Repository\Analytics\AnalyticsAggregatedDataRepository;
use App\Repository\Analytics\AnalyticsOrganizationRepository;
use App\Repository\Organization\OrganizationRepository;

/**
 * Формирует данные для HR-дашборда (вкладка «Отдел кадров»).
 * Понедельные строки analytics_aggregated_data сворачиваются в выбранный масштаб.
 */
final class HrDashboardDataService
{
    public const SCALE_MONTH = 'month';
    public const SCALE_WEEK = 'week';

    private const MONTH_LABELS_RU = [
        1 => 'Янв', 2 => 'Фев', 3 => 'Мар', 4 => 'Апр',
        5 => 'Май', 6 => 'Июн', 7 => 'Июл', 8 => 'Авг',
        9 => 'Сен', 10 => 'Окт', 11 => 'Ноя', 12 => 'Дек',
    ];

    public const KEY_HEADCOUNT = 'actual_number_of_employees';
    public const KEY_HIRED = 'hired_employees';
    public const KEY_FIRED = 'fired_employees';
    public const KEY_FILL_RATE = 'staff_fill_rate';

    /** Потоковые метрики: суммируются за период */
    private const SUM_KEYS = [
        self::KEY_HIRED,
        self::KEY_FIRED,
    ];

    /** Остаточные метрики: берётся среднее за период */
    private const AVG_KEYS = [
        self::KEY_HEADCOUNT,
        self::KEY_FILL_RATE,
    ];

    private const ALL_KEYS = [
        self::KEY_HEADCOUNT,
        self::KEY_HIRED,
        self::KEY_FIRED,
        self::KEY_FILL_RATE,
    ];

    public function __construct(
        private readonly AnalyticsAggregatedDataRepository $aggregatedDataRepo,
        private readonly AnalyticsOrganizationRepository $analyticsOrgRepo,
        private readonly OrganizationRepository $organizationRepo,
    ) {
    }

    /**
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
     * Сравнение организаций за конкретный период (последний или заданный).
     *
     * @return array{scale: string, selectedYear: int, selectedPeriod: int, availablePeriods: array, rows: array}
     */
    public function getCompareData(int $organizationId, string $scale = self::SCALE_MONTH, ?int $year = null, ?int $period = null): array
    {
        $analyticsOrgs = $this->analyticsOrgRepo->findVisibleOrdered();

        if (empty($analyticsOrgs)) {
            return $this->emptyCompareData($scale);
        }

        $orgMap = [];
        $descendantToParent = [];
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

        $availablePeriods = $scale === self::SCALE_WEEK
            ? $this->aggregatedDataRepo->findAvailableWeeks($allOrgIds)
            : $this->aggregatedDataRepo->findAvailableMonths($allOrgIds);

        if (empty($availablePeriods)) {
            return $this->emptyCompareData($scale);
        }

        if ($year === null || $period === null) {
            $latest = $availablePeriods[0];
            $year = (int) $latest['yr'];
            $period = (int) ($scale === self::SCALE_WEEK ? $latest['wk'] : $latest['mo']);
        }

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
                    'label' => self::MONTH_LABELS_RU[(int) $ap['mo']] . ' ' . $ap['yr'],
                ];
            }
        }

        $rows = $scale === self::SCALE_WEEK
            ? $this->aggregatedDataRepo->findCompareWeekly($allOrgIds, self::ALL_KEYS, $year, $period)
            : $this->aggregatedDataRepo->findCompareMonthly($allOrgIds, self::ALL_KEYS, $year, $period);

        $orgData = [];
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

        $resultRows = [];
        $totals = array_fill_keys(self::ALL_KEYS, 0.0);

        foreach ($orgMap as $parentId => $info) {
            $data = $orgData[$parentId] ?? [];
            $resultRows[] = $this->buildCompareRow($info['name'], $data, false);

            foreach (self::ALL_KEYS as $k) {
                $totals[$k] += $data[$k] ?? 0.0;
            }
        }

        // Для % укомплектованности в строке «Итого» нужно среднее, а не сумма
        $orgCount = max(1, count($orgMap));
        $totals[self::KEY_FILL_RATE] = $totals[self::KEY_FILL_RATE] / $orgCount;

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
        $headcount = (int) round($data[self::KEY_HEADCOUNT] ?? 0);
        $hired = (int) round($data[self::KEY_HIRED] ?? 0);
        $fired = (int) round($data[self::KEY_FIRED] ?? 0);
        $turnover = $headcount > 0 ? round($fired / $headcount * 100, 1) : 0.0;

        return [
            'name' => $name,
            'isTotal' => $isTotal,
            'headcount' => $headcount,
            'fillRate' => round($data[self::KEY_FILL_RATE] ?? 0, 1),
            'hired' => $hired,
            'fired' => $fired,
            'netChange' => $hired - $fired,
            'turnoverPct' => $turnover,
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
     * @return int[]
     */
    private function resolveOrganizationIds(int $orgId): array
    {
        if ($orgId === 0) {
            $parents = $this->organizationRepo->findAllParentOrganizations();
            $ids = [];
            foreach ($parents as $parent) {
                $ids = array_merge(
                    $ids,
                    $this->organizationRepo->findOrganizationWithChildrenIds($parent->getId())
                );
            }

            return array_values(array_unique($ids));
        }

        return $this->organizationRepo->findOrganizationWithChildrenIds($orgId);
    }

    /**
     * @param array<int, array<string, int|string|null>> $rows
     * @return array<string, mixed>
     */
    private function buildResponse(array $rows, string $scale): array
    {
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
        ksort($periodKeys);

        $labels = [];
        $periodOrder = [];
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

        $indexed = [];
        foreach ($rows as $row) {
            $key = $scale === self::SCALE_WEEK
                ? $row['yr'] . '-W' . str_pad((string) $row['wk'], 2, '0', STR_PAD_LEFT)
                : $row['yr'] . '-' . str_pad((string) $row['mo'], 2, '0', STR_PAD_LEFT);
            $bk = $row['business_key'];

            $indexed[$bk][$key] = in_array($bk, self::SUM_KEYS, true)
                ? (float) ($row['total_value'] ?? 0)
                : (float) ($row['avg_value'] ?? 0);
        }

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

        $hiredSeries = array_map(static fn(float $v): int => (int) round($v), $series[self::KEY_HIRED]);
        $firedSeries = array_map(static fn(float $v): int => (int) round($v), $series[self::KEY_FIRED]);
        $headcountSeries = array_map(static fn(float $v): int => (int) round($v), $series[self::KEY_HEADCOUNT]);
        $fillRateSeries = array_map(static fn(float $v): float => round($v, 1), $series[self::KEY_FILL_RATE]);

        $headcountLast = $this->lastNonZero($series[self::KEY_HEADCOUNT]);
        $fillRateLast = $this->lastNonZero($series[self::KEY_FILL_RATE]);
        $hiredTotal = (int) array_sum($hiredSeries);
        $firedTotal = (int) array_sum($firedSeries);
        $netChange = $hiredTotal - $firedTotal;
        $turnoverPct = $headcountLast > 0
            ? round($firedTotal / $headcountLast * 100, 1)
            : 0.0;

        return [
            'scale' => $scale,
            'labels' => $labels,
            'hr' => [
                'kpis' => [
                    'headcount' => (int) round($headcountLast),
                    'fillRatePct' => round($fillRateLast, 1),
                    'hired' => $hiredTotal,
                    'fired' => $firedTotal,
                    'netChange' => $netChange,
                    'turnoverPct' => $turnoverPct,
                ],
                'series' => [
                    'headcount' => $headcountSeries,
                    'hired' => $hiredSeries,
                    'fired' => $firedSeries,
                    'fillRatePct' => $fillRateSeries,
                ],
            ],
        ];
    }

    private static function weekRangeLabel(int $year, int $week, bool $multiYear): string
    {
        $monday = new \DateTime();
        $monday->setISODate($year, $week, 1);
        $sunday = clone $monday;
        $sunday->modify('+6 days');

        $label = $monday->format('d.m') . '–' . $sunday->format('d.m');
        if ($multiYear) {
            $label .= "'" . substr((string) $year, 2);
        }

        return $label;
    }

    /**
     * @param float[] $arr
     */
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
            'hr' => [
                'kpis' => [
                    'headcount' => 0,
                    'fillRatePct' => 0,
                    'hired' => 0,
                    'fired' => 0,
                    'netChange' => 0,
                    'turnoverPct' => 0,
                ],
                'series' => [
                    'headcount' => [],
                    'hired' => [],
                    'fired' => [],
                    'fillRatePct' => [],
                ],
            ],
        ];
    }
}
