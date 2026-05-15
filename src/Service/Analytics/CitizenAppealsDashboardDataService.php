<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Repository\Analytics\AnalyticsAggregatedDataRepository;
use App\Repository\Organization\OrganizationRepository;

/**
 * Данные для дашборда «Обращение граждан».
 * Разрез — по городам внутри одной организации (по суффиксу business_key).
 */
final class CitizenAppealsDashboardDataService
{
    public const SCALE_MONTH = 'month';
    public const SCALE_WEEK = 'week';

    private const MONTH_LABELS_RU = [
        1 => 'Янв', 2 => 'Фев', 3 => 'Мар', 4 => 'Апр',
        5 => 'Май', 6 => 'Июн', 7 => 'Июл', 8 => 'Авг',
        9 => 'Сен', 10 => 'Окт', 11 => 'Ноя', 12 => 'Дек',
    ];

    /** city slug => human label */
    private const CITY_LABELS = [
        'gorlovka' => 'Горловка г.о.',
        'donetsk' => 'Донецк г.о.',
        'enakievo' => 'Енакиево г.о.',
        'makeevka' => 'Макеевка г.о.',
        'shakhtersk' => 'Шахтёрск г.о.',
        'mariupol' => 'Мариуполь г.о.',
        'yasinovataya' => 'Ясиноватая',
    ];

    private const PREFIX_CALLS = 'calls_';
    private const PREFIX_APPEALS = 'appeals_';

    public function __construct(
        private readonly AnalyticsAggregatedDataRepository $aggregatedDataRepo,
        private readonly OrganizationRepository $organizationRepo,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(int $organizationId, string $scale = self::SCALE_WEEK): array
    {
        $orgIds = $this->resolveOrganizationIds($organizationId);

        if (empty($orgIds)) {
            return $this->emptyData($scale);
        }

        $keys = $this->allBusinessKeys();

        $rows = $scale === self::SCALE_WEEK
            ? $this->aggregatedDataRepo->findWeeklyAggregated($orgIds, $keys)
            : $this->aggregatedDataRepo->findMonthlyAggregated($orgIds, $keys);

        return $this->buildResponse($rows, $scale);
    }

    /**
     * @return array{scale: string, selectedYear: int, selectedPeriod: int, availablePeriods: array, rows: array}
     */
    public function getCompareData(int $organizationId, string $scale = self::SCALE_WEEK, ?int $year = null, ?int $period = null): array
    {
        $orgIds = $this->resolveOrganizationIds($organizationId);

        if (empty($orgIds)) {
            return $this->emptyCompareData($scale);
        }

        $availablePeriods = $scale === self::SCALE_WEEK
            ? $this->aggregatedDataRepo->findAvailableWeeks($orgIds)
            : $this->aggregatedDataRepo->findAvailableMonths($orgIds);

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

        $keys = $this->allBusinessKeys();
        $rows = $scale === self::SCALE_WEEK
            ? $this->aggregatedDataRepo->findCompareWeekly($orgIds, $keys, $year, $period)
            : $this->aggregatedDataRepo->findCompareMonthly($orgIds, $keys, $year, $period);

        // city => ['calls' => x, 'appeals' => y]
        $cityData = array_fill_keys(array_keys(self::CITY_LABELS), ['calls' => 0.0, 'appeals' => 0.0]);

        foreach ($rows as $row) {
            $bk = (string) $row['business_key'];
            $val = (float) ($row['total_value'] ?? 0);

            if (str_starts_with($bk, self::PREFIX_CALLS)) {
                $city = substr($bk, strlen(self::PREFIX_CALLS));
                if (isset($cityData[$city])) {
                    $cityData[$city]['calls'] += $val;
                }
            } elseif (str_starts_with($bk, self::PREFIX_APPEALS)) {
                $city = substr($bk, strlen(self::PREFIX_APPEALS));
                if (isset($cityData[$city])) {
                    $cityData[$city]['appeals'] += $val;
                }
            }
        }

        $resultRows = [];
        $totals = ['calls' => 0.0, 'appeals' => 0.0];

        foreach (self::CITY_LABELS as $city => $name) {
            $calls = $cityData[$city]['calls'];
            $appeals = $cityData[$city]['appeals'];
            $totals['calls'] += $calls;
            $totals['appeals'] += $appeals;

            $resultRows[] = $this->buildCompareRow($name, $calls, $appeals, false);
        }

        $totalRow = $this->buildCompareRow('Итого', $totals['calls'], $totals['appeals'], true);
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
     * @return array<string, mixed>
     */
    private function buildCompareRow(string $name, float $calls, float $appeals, bool $isTotal): array
    {
        $callsInt = (int) round($calls);
        $appealsInt = (int) round($appeals);

        return [
            'name' => $name,
            'isTotal' => $isTotal,
            'calls' => $callsInt,
            'appeals' => $appealsInt,
            'conversionPct' => $callsInt > 0 ? round($appealsInt / $callsInt * 100, 1) : 0.0,
        ];
    }

    /**
     * @return string[]
     */
    private function allBusinessKeys(): array
    {
        $keys = [];
        foreach (array_keys(self::CITY_LABELS) as $city) {
            $keys[] = self::PREFIX_CALLS . $city;
            $keys[] = self::PREFIX_APPEALS . $city;
        }

        return $keys;
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

        $callsSeries = array_fill(0, $totalPoints, 0);
        $appealsSeries = array_fill(0, $totalPoints, 0);

        foreach ($rows as $row) {
            $key = $scale === self::SCALE_WEEK
                ? $row['yr'] . '-W' . str_pad((string) $row['wk'], 2, '0', STR_PAD_LEFT)
                : $row['yr'] . '-' . str_pad((string) $row['mo'], 2, '0', STR_PAD_LEFT);

            if (!isset($periodOrder[$key])) {
                continue;
            }

            $bk = (string) $row['business_key'];
            $val = (float) ($row['total_value'] ?? 0);

            if (str_starts_with($bk, self::PREFIX_CALLS)) {
                $callsSeries[$periodOrder[$key]] += (int) round($val);
            } elseif (str_starts_with($bk, self::PREFIX_APPEALS)) {
                $appealsSeries[$periodOrder[$key]] += (int) round($val);
            }
        }

        $totalCalls = (int) array_sum($callsSeries);
        $totalAppeals = (int) array_sum($appealsSeries);
        $conversionPct = $totalCalls > 0
            ? round($totalAppeals / $totalCalls * 100, 1)
            : 0.0;

        return [
            'scale' => $scale,
            'labels' => $labels,
            'appeals' => [
                'kpis' => [
                    'totalCalls' => $totalCalls,
                    'totalAppeals' => $totalAppeals,
                    'conversionPct' => $conversionPct,
                ],
                'series' => [
                    'calls' => $callsSeries,
                    'appeals' => $appealsSeries,
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
     * @return array<string, mixed>
     */
    private function emptyData(string $scale): array
    {
        return [
            'scale' => $scale,
            'labels' => [],
            'appeals' => [
                'kpis' => [
                    'totalCalls' => 0,
                    'totalAppeals' => 0,
                    'conversionPct' => 0,
                ],
                'series' => [
                    'calls' => [],
                    'appeals' => [],
                ],
            ],
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
}
