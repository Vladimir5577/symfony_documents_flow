<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Analytics;

use App\Entity\Polygon\Polygon;
use App\Repository\Analytics\TKO\AnalyticsTKORepository;
use App\Repository\Polygon\PolygonRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Вывод аналитики ТКО для SPA (только чтение).
 * Отдаёт недельную сетку по одному полигону: строки = метрики, колонки = 7 дней (Пн–Вс).
 * JSON-зеркало App\Controller\Analytics\TKO\AnalyticsTKOController::index().
 */
#[IsGranted('ROLE_MANAGER')]
final class TkoAnalyticsController extends AbstractController
{
    /**
     * Метрики таблицы: key, label (для index), name, unit (для summary/series), type.
     */
    private const METRICS = [
        ['key' => 'garbage_trucks_volume',   'label' => 'Мусоровозы',            'name' => 'Мусоровозы',            'unit' => 'м³',  'type' => 'num'],
        ['key' => 'garbage_trucks_weight',   'label' => 'Вес ТКО мусоровозы',    'name' => 'Вес ТКО мусоровозы',    'unit' => 'т',   'type' => 'num'],
        ['key' => 'containers_volume',       'label' => 'Контейнеры',            'name' => 'Контейнеры',            'unit' => 'м³',  'type' => 'num'],
        ['key' => 'scrap_trucks_volume',     'label' => 'Ломовозы',              'name' => 'Ломовозы',              'unit' => 'м³',  'type' => 'num'],
        ['key' => 'containers_scrap_weight', 'label' => 'Вес ТКО конт., ломов',  'name' => 'Вес ТКО конт., ломов',  'unit' => 'т',   'type' => 'num'],
        ['key' => 'vegetation_volume',       'label' => 'Растительные',          'name' => 'Растительные',          'unit' => 'м³',  'type' => 'num'],
        ['key' => 'construction_volume',     'label' => 'Строительные',          'name' => 'Строительные',          'unit' => 'м³',  'type' => 'num'],
        ['key' => 'terminal_volume',         'label' => 'Терминал',              'name' => 'Терминал',              'unit' => 'м³',  'type' => 'num'],
        ['key' => 'machinery_work',          'label' => 'Работа техники',        'name' => 'Работа техники',        'unit' => 'дн.', 'type' => 'text'],
        ['key' => 'fire_condition',          'label' => 'Пожары',                'name' => 'Пожары',                'unit' => 'дн.', 'type' => 'text'],
    ];

    private const DOW = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

    private const DEFAULT_LIMIT = 3;
    private const MAX_LIMIT = 12;

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
     */
    #[Route('/spa/api/analytics/tko', name: 'spa_api_analytics_tko', methods: ['GET'])]
    public function index(
        Request $request,
        PolygonRepository $polygonRepository,
        AnalyticsTKORepository $analyticsRepository,
    ): JsonResponse {
        $polygons = $polygonRepository->findBy(['isActive' => true], ['name' => 'ASC']);

        // Выбранный полигон: из запроса либо первый из списка
        $selectedPolygon = null;
        $polygonId = $request->query->getInt('polygon_id');
        if ($polygonId > 0) {
            $selectedPolygon = $polygonRepository->find($polygonId);
        }
        if (null === $selectedPolygon && [] !== $polygons) {
            $selectedPolygon = $polygons[0];
        }

        $monday = $this->resolveMonday($request->query->getString('week'));
        $sunday = $monday->modify('+6 days');

        // Загружаем записи недели и раскладываем по дате
        $byDate = [];
        if (null !== $selectedPolygon) {
            foreach ($analyticsRepository->findByPolygonAndDateRange($selectedPolygon, $monday, $sunday) as $record) {
                $byDate[$record->getReportDate()->format('Y-m-d')] = $record;
            }
        }

        $days = [];
        for ($i = 0; $i < 7; ++$i) {
            $date = $monday->modify(sprintf('+%d days', $i));
            $key = $date->format('Y-m-d');
            $record = $byDate[$key] ?? null;

            $values = [];
            foreach (self::METRICS as $metric) {
                $raw = null !== $record ? $record->{$this->getter($metric['key'])}() : null;
                $values[$metric['key']] = 'num' === $metric['type']
                    ? $this->normalizeNumber($raw)
                    : (string) ($raw ?? '');
            }

            $days[] = [
                'date' => $key,
                'dow' => self::DOW[$i],
                'short' => $date->format('d.m'),
                'values' => $values,
            ];
        }

        return $this->json([
            'polygons' => array_map(
                static fn ($p) => ['id' => $p->getId(), 'name' => $p->getName()],
                $polygons,
            ),
            'selectedPolygonId' => $selectedPolygon?->getId(),
            'week' => $monday->format('Y-m-d'),
            'weekLabel' => sprintf('%s — %s', $monday->format('d.m'), $sunday->format('d.m')),
            'prevWeek' => $monday->modify('-7 days')->format('Y-m-d'),
            'nextWeek' => $monday->modify('+7 days')->format('Y-m-d'),
            'metrics' => self::METRICS,
            'days' => $days,
        ]);
    }

    /**
     * Календарь недель + агрегаты по неделям (series с разбивкой по полигонам).
     * Формат:
     *   {
     *     availableWeeks: [{ startDate, endDate }],
     *     weeks: [{ startDate, endDate, series: [{ metric_key, valueNumber, children: [...] }] }]
     *   }
     */
    #[Route('/spa/api/analytics/tko/summary', name: 'spa_api_analytics_tko_summary', methods: ['GET'])]
    public function summary(
        Request $request,
        ManagerRegistry $managerRegistry,
        PolygonRepository $polygonRepository,
    ): JsonResponse {
        $limit = $request->query->getInt('limit', self::DEFAULT_LIMIT);
        $offset = $request->query->getInt('offset', 0);

        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        } elseif ($limit > self::MAX_LIMIT) {
            $limit = self::MAX_LIMIT;
        }
        if ($offset < 0) {
            $offset = 0;
        }

        $connection = $managerRegistry->getConnection();

        return $this->json($this->buildWeeksSummary(
            $connection,
            $polygonRepository,
            $limit,
            $offset,
            $this->findAvailableWeeks($connection),
        ));
    }

    // -------------------------------------------------------------------------
    // Функции сервиса (сборка availableWeeks + weeks / series)
    // -------------------------------------------------------------------------

    /**
     * @param list<array{startDate: string, endDate: string}> $availableWeeks
     *
     * @return array{
     *     availableWeeks: list<array{startDate: string, endDate: string}>,
     *     weeks: list<array{startDate: string, endDate: string, series: list<array<string, mixed>>}>
     * }
     */
    private function buildWeeksSummary(
        Connection $connection,
        PolygonRepository $polygonRepository,
        int $limit,
        int $offset,
        array $availableWeeks,
    ): array {
        $polygons = $polygonRepository->findBy(['isActive' => true], ['name' => 'ASC']);

        [$pageWeeks] = $this->paginateWeeks($availableWeeks, $limit, $offset);
        if ([] === $pageWeeks || [] === $polygons) {
            return [
                'availableWeeks' => $availableWeeks,
                'weeks' => [],
            ];
        }

        $sliceFrom = new \DateTimeImmutable($pageWeeks[0]['startDate']);
        $sliceTo = new \DateTimeImmutable($pageWeeks[array_key_last($pageWeeks)]['endDate']);
        $rows = $this->aggregateWeeklyByPolygon($connection, $sliceFrom, $sliceTo);

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
                'series' => $this->buildSeries($polygonRows, $polygons, self::METRICS),
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

    private function getter(string $key): string
    {
        return 'get' . str_replace('_', '', ucwords($key, '_'));
    }


    // -------------------------------------------------------------------------
    // Функции репозитория (SQL / analytics_tko)
    // -------------------------------------------------------------------------

    /**
     * @return list<array{startDate: string, endDate: string}>
     */
    private function findAvailableWeeks(Connection $connection): array
    {
        $row = $connection->fetchAssociative(
            'SELECT MIN(report_date) AS min_date, MAX(report_date) AS max_date FROM analytics_tko',
        );
        if (false === $row || null === $row['min_date'] || null === $row['max_date']) {
            return [];
        }

        $from = new \DateTimeImmutable($row['min_date'])->modify('monday this week')->setTime(0, 0);
        $to = new \DateTimeImmutable($row['max_date'])->modify('sunday this week')->setTime(0, 0);

        $weeks = [];
        $cursor = $from;
        while ($cursor <= $to) {
            $end = $cursor->modify('+6 days');
            $weeks[] = [
                'startDate' => $cursor->format('Y-m-d'),
                'endDate' => $end->format('Y-m-d'),
            ];
            $cursor = $cursor->modify('+7 days');
        }

        return $weeks;
    }


    /**
     * @return list<array<string, mixed>>
     */
    private function aggregateWeeklyByPolygon(
        Connection $connection,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $sql = <<<'SQL'
            SELECT
                to_char(date_trunc('week', report_date::timestamp), 'YYYY-MM-DD') AS week_start,
                polygon_id,
                SUM(garbage_trucks_volume)                              AS garbage_trucks_volume,
                SUM(garbage_trucks_weight)                              AS garbage_trucks_weight,
                SUM(containers_volume)                                  AS containers_volume,
                SUM(scrap_trucks_volume)                                AS scrap_trucks_volume,
                SUM(containers_scrap_weight)                            AS containers_scrap_weight,
                SUM(vegetation_volume)                                  AS vegetation_volume,
                SUM(construction_volume)                                AS construction_volume,
                SUM(terminal_volume)                                    AS terminal_volume,
                COUNT(NULLIF(btrim(machinery_work), ''))                AS bulldozer_work,
                COUNT(NULLIF(btrim(fire_condition), ''))                AS equipment_work
            FROM analytics_tko
            WHERE report_date BETWEEN :from AND :to
            GROUP BY week_start, polygon_id
            ORDER BY week_start, polygon_id
            SQL;

        return $connection->fetchAllAssociative($sql, [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]);
    }
}
