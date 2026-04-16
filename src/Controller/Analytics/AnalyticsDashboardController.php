<?php

declare(strict_types=1);

namespace App\Controller\Analytics;

use App\Repository\Organization\OrganizationRepository;
use App\Service\Analytics\DashboardDataService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AnalyticsDashboardController extends AbstractController
{
    #[Route('/analytics/dashboard', name: 'app_analytics_dashboard')]
    public function index(
        OrganizationRepository $organizationRepository,
        DashboardDataService $dashboardDataService,
    ): Response {
        $organizations = $organizationRepository->findAllParentOrganizations();

        // Начальные данные: первая организация или все
        $firstOrgId = !empty($organizations) ? $organizations[0]->getId() : 0;
        $dashboardData = $dashboardDataService->getData($firstOrgId);

        return $this->render('analytics/dashboard/dashboard.html.twig', [
            'organizations' => $organizations,
            'dashboardDataJson' => json_encode($dashboardData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    #[Route('/analytics/dashboard/data', name: 'app_analytics_dashboard_data', methods: ['GET'])]
    public function data(
        Request $request,
        DashboardDataService $dashboardDataService,
    ): JsonResponse {
        $orgId = $request->query->getInt('org_id', 0);

        return $this->json($dashboardDataService->getData($orgId));
    }
}
