<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Analytics;

use App\Repository\Analytics\TKO\AnalyticsTKORepository;
use App\Repository\Polygon\PolygonRepository;
use App\Service\Analytics\TkoAnalyticsService;
use App\Service\Analytics\TkoMetrics;
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
    private const MAX_PERIOD_DAYS = 84;
    /**
     * Недельная сетка аналитики ТКО по полигону.
     */
    #[Route('/spa/api/analytics/tko', name: 'spa_api_analytics_tko', methods: ['GET'])]
    public function index(Request $request, TkoAnalyticsService $service): JsonResponse
    {
        return $this->json($service->buildWeekGrid(
            $request->query->getInt('polygon_id'),
            $request->query->getString('week'),
        ));
    }

    /**
     * Календарь недель + агрегаты по неделям (reports с разбивкой по полигонам).
     */
    #[Route('/spa/api/analytics/tko/summary', name: 'spa_api_analytics_tko_summary', methods: ['GET'])]
    public function summary(Request $request, TkoAnalyticsService $service): JsonResponse
    {
        return $this->json($service->buildSummary(
            $request->query->getInt('limit'),
            $request->query->getInt('offset'),
        ));
    }

    /**
     * Недельная сводка по всем полигонам (строки = полигоны, колонки = метрики) + итог.
     * API-формат для SPA: значения — числа или null, даты — ISO; см. Readme_tko_analytics_api.md.
     */
    #[Route('/spa/api/analytics/tko/week', name: 'spa_api_analytics_tko_view_summary', methods: ['GET'])]
    public function viewSummary(Request $request, TkoAnalyticsService $service): JsonResponse
    {
        return $this->json($service->buildWeekSummary(
            $request->query->getString('week'),
        ));
    }


    /**
     * Суточная сетка аналитики ТКО по полигону за произвольный период.
     * Query: polygon_id, startDate (Y-m-d), endDate (Y-m-d).
     * Формат:
     *   {
     *     polygons: [{ id, name }],
     *     selectedPolygonId,
     *     startDate, endDate, periodLabel,
     *     metrics: [{ key, label, type }],
     *     days: [{ date, dow, short, values: { <metricKey>: string } }]
     *   }
     */
    #[Route('/spa/api/analytics/tko-custom-period', name: 'spa_api_analytics_tko_custom_period', methods: ['GET'])]
    public function customPeriod(
        Request $request,
        PolygonRepository $polygonRepository,
        AnalyticsTKORepository $analyticsRepository,
    ): JsonResponse {
        $startDateRaw = $request->query->getString('startDate');
        $endDateRaw = $request->query->getString('endDate');

        if ('' === $startDateRaw || '' === $endDateRaw) {
            return $this->json(['error' => 'start_date_and_end_date_required'], 400);
        }

        try {
            $startDate = new \DateTimeImmutable($startDateRaw)->setTime(0, 0);
            $endDate = new \DateTimeImmutable($endDateRaw)->setTime(0, 0);
        } catch (\Exception) {
            return $this->json(['error' => 'invalid_date_format'], 400);
        }

        if ($startDate > $endDate) {
            return $this->json(['error' => 'invalid_date_range'], 400);
        }

        $periodDays = (int) $startDate->diff($endDate)->days + 1;
        if ($periodDays > self::MAX_PERIOD_DAYS) {
            return $this->json(['error' => 'period_too_long', 'maxDays' => self::MAX_PERIOD_DAYS], 400);
        }

        $polygons = $polygonRepository->findBy(['isActive' => true], ['name' => 'ASC']);

        $selectedPolygon = null;
        $polygonId = $request->query->getInt('polygon_id');
        if ($polygonId > 0) {
            $selectedPolygon = $polygonRepository->find($polygonId);
        }
        if (null === $selectedPolygon && [] !== $polygons) {
            $selectedPolygon = $polygons[0];
        }

        $byDate = [];
        if (null !== $selectedPolygon) {
            foreach ($analyticsRepository->findByPolygonAndDateRange($selectedPolygon, $startDate, $endDate) as $record) {
                $byDate[$record->getReportDate()->format('Y-m-d')] = $record;
            }
        }

        $days = $this->buildDaysForRange($startDate, $endDate, $byDate);

        return $this->json([
            'polygons' => array_map(
                static fn ($p) => ['id' => $p->getId(), 'name' => $p->getName()],
                $polygons,
            ),
            'selectedPolygonId' => $selectedPolygon?->getId(),
            'startDate' => $startDate->format('Y-m-d'),
            'endDate' => $endDate->format('Y-m-d'),
            'periodLabel' => sprintf('%s — %s', $startDate->format('d.m'), $endDate->format('d.m')),
            'metrics' => TkoMetrics::METRICS,
            'days' => $days,
        ]);
    }

    /**
     * @param array<string, object> $byDate
     *
     * @return list<array{date: string, dow: string, short: string, values: array<string, string>}>
     */
    private function buildDaysForRange(
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        array $byDate,
    ): array {
        $days = [];
        $cursor = $startDate;

        while ($cursor <= $endDate) {
            $key = $cursor->format('Y-m-d');
            $record = $byDate[$key] ?? null;
            $dowIndex = (int) $cursor->format('N') - 1;

            $values = [];
            foreach (TkoMetrics::METRICS as $metric) {
                $raw = null !== $record ? $record->{$this->getter($metric['key'])}() : null;
                $values[$metric['key']] = 'num' === $metric['type']
                    ? $this->normalizeNumber($raw)
                    : (string) ($raw ?? '');
            }

            $days[] = [
                'date' => $key,
                'dow' => TkoMetrics::DOW[$dowIndex],
                'short' => $cursor->format('d.m'),
                'values' => $values,
            ];

            $cursor = $cursor->modify('+1 day');
        }

        return $days;
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
