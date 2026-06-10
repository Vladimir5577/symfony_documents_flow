<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Polygon\Polygon;
use App\Repository\Analytics\TKO\AnalyticsTKORepository;
use App\Repository\Polygon\PolygonRepository;

/**
 * Сборка аналитики ТКО для SPA (только чтение).
 * Недельная сетка по одному полигону (buildWeekGrid) и календарь недель
 * с агрегатами по полигонам (buildSummary).
 */
final class TkoAnalyticsService
{
    private const DEFAULT_LIMIT = 3;
    private const MAX_LIMIT = 12;

    public function __construct(
        private readonly PolygonRepository $polygonRepository,
        private readonly AnalyticsTKORepository $analyticsRepository,
    ) {
    }

    /**
     * Недельная сетка аналитики ТКО по полигону.
     * Формат:
     *   {
     *     polygons: [{ id, name }],
     *     selectedPolygonId,
     *     week, weekLabel, prevWeek, nextWeek,
     *     metrics: [{ key, label, type }],
     *     days: [{ date, dow, short, values: { <metricKey>: string } }]
     *   }
     *
     * @return array<string, mixed>
     */
    public function buildWeekGrid(int $polygonId, string $week): array
    {
        $polygons = $this->polygonRepository->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'name' => 'ASC']);

        // Выбранный полигон: из запроса либо первый из списка
        $selectedPolygon = null;
        if ($polygonId > 0) {
            $selectedPolygon = $this->polygonRepository->find($polygonId);
        }
        if (null === $selectedPolygon && [] !== $polygons) {
            $selectedPolygon = $polygons[0];
        }

        $monday = $this->resolveMonday($week);
        $sunday = $monday->modify('+6 days');

        // Загружаем записи недели и раскладываем по дате
        $byDate = [];
        if (null !== $selectedPolygon) {
            foreach ($this->analyticsRepository->findByPolygonAndDateRange($selectedPolygon, $monday, $sunday) as $record) {
                $byDate[$record->getReportDate()->format('Y-m-d')] = $record;
            }
        }

        $days = [];
        for ($i = 0; $i < 7; ++$i) {
            $date = $monday->modify(sprintf('+%d days', $i));
            $key = $date->format('Y-m-d');
            $record = $byDate[$key] ?? null;

            $values = [];
            foreach (TkoMetrics::METRICS as $metric) {
                $raw = null !== $record ? $record->{$this->getter($metric['key'])}() : null;
                $values[$metric['key']] = 'num' === $metric['type']
                    ? $this->normalizeNumber($raw)
                    : (string) ($raw ?? '');
            }

            $days[] = [
                'date' => $key,
                'dow' => TkoMetrics::DOW[$i],
                'short' => $date->format('d.m'),
                'values' => $values,
            ];
        }

        return [
            'polygons' => array_map(
                static fn ($p) => ['id' => $p->getId(), 'name' => $p->getName()],
                $polygons,
            ),
            'selectedPolygonId' => $selectedPolygon?->getId(),
            'week' => $monday->format('Y-m-d'),
            'weekLabel' => sprintf('%s — %s', $monday->format('d.m'), $sunday->format('d.m')),
            'prevWeek' => $monday->modify('-7 days')->format('Y-m-d'),
            'nextWeek' => $monday->modify('+7 days')->format('Y-m-d'),
            'metrics' => TkoMetrics::METRICS,
            'days' => $days,
        ];
    }

    /**
     * Недельная сводка по всем полигонам (строки = полигоны, колонки = метрики) + итог.
     * API-формат для SPA: значения — числа или null, даты — ISO (Y-m-d), без презентации.
     * Формат:
     *   {
     *     week: { start, end, prev, next },
     *     metrics: [{ key, name, unit, agg }],
     *     rows: [{ polygonId, name, values: { <metricKey>: number|null } }],
     *     totals: { <metricKey>: number|null }
     *   }
     *
     * agg описывает семантику колонки: 'sum' — сумма за неделю (num-метрики),
     * 'daysCount' — число дней недели с заполненным значением (text-метрики).
     *
     * @return array<string, mixed>
     */
    public function buildWeekSummary(string $week): array
    {
        $polygons = $this->polygonRepository->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'name' => 'ASC']);

        $monday = $this->resolveMonday($week);
        $sunday = $monday->modify('+6 days');

        // Недельные агрегаты по всем полигонам за выбранную неделю
        $byPolygon = [];
        foreach ($this->analyticsRepository->aggregateWeeklyByPolygon($monday, $sunday) as $row) {
            $byPolygon[(int) $row['polygon_id']] = $row;
        }

        // Строка на каждый активный полигон + итог по всем (числовые суммируются, текстовые — дни с отметкой)
        $metricKeys = array_column(TkoMetrics::METRICS, 'key');
        $rows = [];
        $totalsSum = array_fill_keys($metricKeys, 0.0);
        $totalsHas = array_fill_keys($metricKeys, false);
        foreach ($polygons as $polygon) {
            $agg = $byPolygon[$polygon->getId()] ?? [];
            $values = [];
            foreach ($metricKeys as $key) {
                $raw = $agg[$key] ?? null;
                if (null !== $raw && '' !== $raw && is_numeric($raw)) {
                    $value = (float) $raw;
                    $totalsSum[$key] += $value;
                    $totalsHas[$key] = true;
                    $values[$key] = $this->toNumber($value);
                } else {
                    $values[$key] = null;
                }
            }

            $rows[] = [
                'polygonId' => $polygon->getId(),
                'name' => $polygon->getName(),
                'values' => $values,
            ];
        }

        // Итог: null, если ни у одного полигона не было значения по метрике
        $totals = [];
        foreach ($metricKeys as $key) {
            $totals[$key] = $totalsHas[$key] ? $this->toNumber($totalsSum[$key]) : null;
        }

        return [
            'week' => [
                'start' => $monday->format('Y-m-d'),
                'end' => $sunday->format('Y-m-d'),
                'prev' => $monday->modify('-7 days')->format('Y-m-d'),
                'next' => $monday->modify('+7 days')->format('Y-m-d'),
            ],
            'metrics' => array_map(
                static fn (array $m): array => [
                    'key' => $m['key'],
                    'name' => $m['name'],
                    'unit' => $m['unit'],
                    'agg' => 'text' === $m['type'] ? 'daysCount' : 'sum',
                ],
                TkoMetrics::METRICS,
            ),
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * Календарь недель + агрегаты по неделям (reports с разбивкой по полигонам).
     * Формат:
     *   {
     *     availableWeeks: [{ startDate, endDate }],
     *     weeks: [{ startDate, endDate, reports: [{ metric_key, valueNumber, children: [...] }] }]
     *   }
     *
     * @return array{
     *     availableWeeks: list<array{startDate: string, endDate: string}>,
     *     weeks: list<array{startDate: string, endDate: string, reports: list<array<string, mixed>>}>
     * }
     */
    public function buildSummary(int $limit, int $offset): array
    {
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        } elseif ($limit > self::MAX_LIMIT) {
            $limit = self::MAX_LIMIT;
        }
        if ($offset < 0) {
            $offset = 0;
        }

        $availableWeeks = $this->analyticsRepository->findAvailableWeeks();
        $polygons = $this->polygonRepository->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'name' => 'ASC']);

        [$pageWeeks] = $this->paginateWeeks($availableWeeks, $limit, $offset);
        if ([] === $pageWeeks || [] === $polygons) {
            return [
                'availableWeeks' => $availableWeeks,
                'weeks' => [],
            ];
        }

        $sliceFrom = new \DateTimeImmutable($pageWeeks[0]['startDate']);
        $sliceTo = new \DateTimeImmutable($pageWeeks[array_key_last($pageWeeks)]['endDate']);
        $rows = $this->analyticsRepository->aggregateWeeklyByPolygon($sliceFrom, $sliceTo);

        /** @var array<string, array<int, array<string, mixed>>> $byWeekPolygon */
        $byWeekPolygon = [];
        foreach ($rows as $row) {
            $weekStart = $row['week_start'];
            $pid = (int) $row['polygon_id'];
            unset($row['week_start'], $row['polygon_id']);
            $byWeekPolygon[$weekStart][$pid] = $row;
        }

        $weeks = [];
        foreach ($pageWeeks as $week) {
            $weekStart = $week['startDate'];
            $polygonRows = $byWeekPolygon[$weekStart] ?? [];

            $weeks[] = [
                'startDate' => $weekStart,
                'endDate' => $week['endDate'],
                'reports' => $this->buildSeries($polygonRows, $polygons, TkoMetrics::METRICS),
            ];
        }

        return [
            'availableWeeks' => $availableWeeks,
            'weeks' => $weeks,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $polygonRows
     * @param list<Polygon> $polygons
     * @param list<array{key: string, label: string, name: string, unit: string, type: string}> $metrics
     *
     * @return list<array<string, mixed>>
     */
    private function buildSeries(array $polygonRows, array $polygons, array $metrics): array
    {
        $series = [];

        foreach ($metrics as $metric) {
            $children = [];
            $parentTotal = 0.0;
            $hasParent = false;

            foreach ($polygons as $polygon) {
                $pid = $polygon->getId();
                $raw = $polygonRows[$pid][$metric['key']] ?? null;
                $value = $this->toValueNumber($raw, $metric['type']);

                $children[] = [
                    'metric_key' => $metric['key'] . '_' . $pid,
                    'name' => $polygon->getName(),
                    'unit' => $metric['unit'],
                    'valueNumber' => $value,
                ];

                if (null !== $value) {
                    $hasParent = true;
                    $parentTotal += $value;
                }
            }

            $series[] = [
                'metric_key' => $metric['key'],
                'name' => $metric['name'],
                'unit' => $metric['unit'],
                'valueNumber' => $hasParent ? $parentTotal : null,
                'children' => $children,
            ];
        }

        return $series;
    }

    private function toValueNumber(string|int|float|null $raw, string $type): ?float
    {
        if (null === $raw) {
            return null;
        }

        if (\is_string($raw)) {
            if ('' === $raw) {
                return null;
            }
            if (!is_numeric($raw)) {
                return null;
            }
        }

        if ('text' === $type) {
            return (float) (int) $raw;
        }

        return (float) $raw;
    }

    /**
     * @param list<array{startDate: string, endDate: string}> $all
     *
     * @return array{0: list<array{startDate: string, endDate: string}>, 1: int}
     */
    private function paginateWeeks(array $all, int $limit, int $offset): array
    {
        $total = \count($all);
        if (0 === $total) {
            return [[], 0];
        }

        $endIndex = $total - $offset;
        if ($endIndex <= 0) {
            return [[], $total];
        }

        $startIndex = max(0, $endIndex - $limit);

        return [array_slice($all, $startIndex, $endIndex - $startIndex), $total];
    }

    private function resolveMonday(string $week): \DateTimeImmutable
    {
        try {
            $base = '' !== $week ? new \DateTimeImmutable($week) : new \DateTimeImmutable('today');
        } catch (\Exception) {
            $base = new \DateTimeImmutable('today');
        }

        return $base->modify('monday this week')->setTime(0, 0);
    }

    private function normalizeNumber(?string $value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value;
    }

    /**
     * Приводит число к int (если целое) или float — чтобы в JSON не было лишнего «.0».
     */
    private function toNumber(float $value): int|float
    {
        return $value == (int) $value ? (int) $value : $value;
    }

    private function getter(string $key): string
    {
        return 'get' . str_replace('_', '', ucwords($key, '_'));
    }
}
