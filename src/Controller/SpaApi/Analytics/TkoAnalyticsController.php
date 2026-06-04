<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Analytics;

use App\Repository\Analytics\TKO\AnalyticsTKORepository;
use App\Repository\Polygon\PolygonRepository;
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
     * Метрики таблицы: ключ = колонка сущности, type = num|text.
     */
    private const METRICS = [
        ['key' => 'garbage_trucks_volume',   'label' => 'Мусоровозы',            'type' => 'num'],
        ['key' => 'garbage_trucks_weight',   'label' => 'Вес ТКО мусоровозы',    'type' => 'num'],
        ['key' => 'containers_volume',       'label' => 'Контейнеры',            'type' => 'num'],
        ['key' => 'scrap_trucks_volume',     'label' => 'Ломовозы',              'type' => 'num'],
        ['key' => 'containers_scrap_weight', 'label' => 'Вес ТКО конт., ломов',  'type' => 'num'],
        ['key' => 'vegetation_volume',       'label' => 'Растительные',          'type' => 'num'],
        ['key' => 'construction_volume',     'label' => 'Строительные',          'type' => 'num'],
        ['key' => 'terminal_volume',         'label' => 'Терминал',              'type' => 'num'],
        ['key' => 'bulldozer_work',          'label' => 'Работа бульдозера',     'type' => 'text'],
        ['key' => 'equipment_work',          'label' => 'Работа техники',        'type' => 'text'],
    ];

    private const DOW = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 10;

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
     * Суммарная аналитика ТКО по полигону с группировкой по неделям или месяцам.
     * Числовые метрики суммируются, текстовые — кол-во дней с отметкой.
     * Формат:
     *   {
     *     polygons: [{ id, name }],
     *     selectedPolygonId,
     *     granularity, from, to,
     *     metrics: [{ key, label, type, aggregate }],   // aggregate: sum | days_count
     *     buckets: [{ key, label, start, end, values: { <metricKey>: string } }]
     *   }
     */
    #[Route('/spa/api/analytics/tko/summary', name: 'spa_api_analytics_tko_summary', methods: ['GET'])]
    public function summary(
        Request $request,
        PolygonRepository $polygonRepository,
        AnalyticsTKORepository $analyticsRepository,
    ): JsonResponse {
        $polygons = $polygonRepository->findBy(['isActive' => true], ['name' => 'ASC']);

        $selectedPolygon = null;
        $polygonId = $request->query->getInt('polygon_id');
        if ($polygonId > 0) {
            $selectedPolygon = $polygonRepository->find($polygonId);
        }
        if (null === $selectedPolygon && [] !== $polygons) {
            $selectedPolygon = $polygons[0];
        }

        $granularity = 'month' === $request->query->getString('granularity') ? 'month' : 'week';
        [$from, $to] = $this->resolveRange(
            $request->query->getString('from'),
            $request->query->getString('to'),
            $granularity,
        );

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

        $allBucketDefs = $this->buildBuckets($from, $to, $granularity);
        [$pageBucketDefs, $total] = $this->paginateBuckets($allBucketDefs, $limit, $offset);

        $buckets = [];
        foreach ($pageBucketDefs as $b) {
            $buckets[$b['key']] = $b + ['values' => $this->emptyValues()];
        }

        if (null !== $selectedPolygon && [] !== $pageBucketDefs) {
            $sliceFrom = $this->parseDate($pageBucketDefs[0]['start']) ?? $from;
            $sliceTo = $this->parseDate($pageBucketDefs[array_key_last($pageBucketDefs)]['end']) ?? $to;
            $aggregated = $analyticsRepository->aggregateByPolygon(
                $selectedPolygon->getId(),
                $sliceFrom,
                $sliceTo,
                $granularity,
            );
            foreach ($aggregated as $bucketKey => $row) {
                if (!isset($buckets[$bucketKey])) {
                    continue;
                }
                foreach (self::METRICS as $metric) {
                    $raw = $row[$metric['key']] ?? null;
                    $buckets[$bucketKey]['values'][$metric['key']] = 'num' === $metric['type']
                        ? $this->normalizeNumber(null === $raw ? null : (string) $raw)
                        : (string) (int) $raw; // кол-во дней с отметкой
                }
            }
        }

        return $this->json([
            'polygons' => array_map(
                static fn ($p) => ['id' => $p->getId(), 'name' => $p->getName()],
                $polygons,
            ),
            'selectedPolygonId' => $selectedPolygon?->getId(),
            'granularity' => $granularity,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
            'metrics' => array_map(
                static fn (array $m) => $m + ['aggregate' => 'num' === $m['type'] ? 'sum' : 'days_count'],
                self::METRICS,
            ),
            'buckets' => array_values($buckets),
        ]);
    }

    /**
     * Пагинация бакетов (недель или месяцев): от свежих к старым, в ответе — ASC.
     *
     * @param array<int, array{key: string, label: string, start: string, end: string}> $all
     *
     * @return array{0: array<int, array{key: string, label: string, start: string, end: string}>, 1: int}
     */
    private function paginateBuckets(array $all, int $limit, int $offset): array
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

    /**
     * Диапазон [from, to]. По умолчанию — текущий месяц.
     * Для недельной группировки расширяем до полных недель (Пн–Вс).
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function resolveRange(string $from, string $to, string $granularity): array
    {
        $start = $this->parseDate($from) ?? new \DateTimeImmutable('first day of this month');
        $end = $this->parseDate($to) ?? $start->modify('last day of this month');

        if ($end < $start) {
            $end = $start;
        }

        if ('week' === $granularity) {
            $start = $start->modify('monday this week');
            $end = $end->modify('sunday this week');
        } else {
            $start = $start->modify('first day of this month');
            $end = $end->modify('last day of this month');
        }

        return [$start->setTime(0, 0), $end->setTime(0, 0)];
    }

    /**
     * Полный список бакетов в диапазоне.
     *
     * @return array<int, array{key: string, label: string, start: string, end: string}>
     */
    private function buildBuckets(\DateTimeImmutable $from, \DateTimeImmutable $to, string $granularity): array
    {
        $buckets = [];
        $cursor = $from;

        while ($cursor <= $to) {
            if ('week' === $granularity) {
                $end = $cursor->modify('+6 days');
                $buckets[] = [
                    'key' => $cursor->format('Y-m-d'),
                    'label' => sprintf('%s — %s', $cursor->format('d.m'), $end->format('d.m')),
                    'start' => $cursor->format('Y-m-d'),
                    'end' => $end->format('Y-m-d'),
                ];
                $cursor = $cursor->modify('+7 days');
            } else {
                $end = $cursor->modify('last day of this month');
                $buckets[] = [
                    'key' => $cursor->format('Y-m-d'),
                    'label' => $cursor->format('m.Y'),
                    'start' => $cursor->format('Y-m-d'),
                    'end' => $end->format('Y-m-d'),
                ];
                $cursor = $cursor->modify('first day of next month');
            }
        }

        return $buckets;
    }

    /**
     * @return array<string, string>
     */
    private function emptyValues(): array
    {
        $values = [];
        foreach (self::METRICS as $metric) {
            $values[$metric['key']] = 'num' === $metric['type'] ? '' : '0';
        }

        return $values;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
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
}
