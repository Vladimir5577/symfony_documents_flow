<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Analytics;

use App\Service\Analytics\DashboardDataService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class FinancialAnalyticsController extends AbstractController
{
    private const ALLOWED_SCALES = [
        DashboardDataService::SCALE_MONTH,
        DashboardDataService::SCALE_WEEK,
    ];

    #[Route('/spa/api/analytics/dashboard/data', name: 'spa_api_analytics_dashboard_data', methods: ['GET'])]
    public function data(
        Request $request,
        DashboardDataService $dashboardDataService,
    ): JsonResponse {
        $orgId = $request->query->getInt('org_id', 0);
        $scaleInput = $request->query->getString('scale', DashboardDataService::SCALE_MONTH);
        $scale = in_array($scaleInput, self::ALLOWED_SCALES, true)
            ? $scaleInput
            : DashboardDataService::SCALE_MONTH;

        $data = $dashboardDataService->getData($orgId, $scale);
        $data['compare'] = $dashboardDataService->getCompareData($orgId, $scale);

        return $this->json($data);
    }
}
