<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Analytics;

use App\Service\Analytics\TkoAnalyticsService;
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
}
