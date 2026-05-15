<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Analytics;

use App\Service\Analytics\CitizenAppealsDashboardDataService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CitizenAppealsAnalyticsController extends AbstractController
{
    private const ALLOWED_SCALES = [
        CitizenAppealsDashboardDataService::SCALE_WEEK,
        CitizenAppealsDashboardDataService::SCALE_MONTH,
    ];

    #[Route('/spa/api/analytics/citizen-appeals/dashboard/data', name: 'spa_api_analytics_citizen_appeals_dashboard_data', methods: ['GET'])]
    public function data(
        Request $request,
        CitizenAppealsDashboardDataService $service,
    ): JsonResponse {
        $orgId = $request->query->getInt('org_id', 0);
        $scaleInput = $request->query->getString('scale', CitizenAppealsDashboardDataService::SCALE_WEEK);
        $scale = in_array($scaleInput, self::ALLOWED_SCALES, true)
            ? $scaleInput
            : CitizenAppealsDashboardDataService::SCALE_WEEK;

        $data = $service->getData($orgId, $scale);

        return $this->json($data);
    }
}
