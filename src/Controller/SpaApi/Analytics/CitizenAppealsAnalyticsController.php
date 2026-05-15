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
    #[Route('/spa/api/analytics/citizen-appeals/dashboard/data', name: 'spa_api_analytics_citizen_appeals_dashboard_data', methods: ['GET'])]
    public function data(
        Request $request,
        CitizenAppealsDashboardDataService $service,
    ): JsonResponse {
        $orgId = $request->query->getInt('org_id', 0);

        return $this->json($service->getData($orgId));
    }
}
