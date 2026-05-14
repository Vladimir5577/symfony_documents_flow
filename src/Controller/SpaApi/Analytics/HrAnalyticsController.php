<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Analytics;

use App\Service\Analytics\HrDashboardDataService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class HrAnalyticsController extends AbstractController
{
    private const ALLOWED_SCALES = [
        HrDashboardDataService::SCALE_MONTH,
        HrDashboardDataService::SCALE_WEEK,
    ];

    #[Route('/spa/api/analytics/hr/dashboard/data', name: 'spa_api_analytics_hr_dashboard_data', methods: ['GET'])]
    public function data(
        Request $request,
        HrDashboardDataService $hrDashboardDataService,
    ): JsonResponse {
        $orgId = $request->query->getInt('org_id', 0);
        $scaleInput = $request->query->getString('scale', HrDashboardDataService::SCALE_MONTH);
        $scale = in_array($scaleInput, self::ALLOWED_SCALES, true)
            ? $scaleInput
            : HrDashboardDataService::SCALE_MONTH;

        $data = $hrDashboardDataService->getData($orgId, $scale);
        $data['compare'] = $hrDashboardDataService->getCompareData($orgId, $scale);

        return $this->json($data);
    }
}
