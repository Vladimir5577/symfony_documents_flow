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
    private const ALLOWED_SCALES = ['month', 'week'];

    private function normalizeScale(string $scale): string
    {
        return in_array($scale, self::ALLOWED_SCALES, true) ? $scale : 'month';
    }

    #[Route('/analytics/dashboard', name: 'app_analytics_dashboard')]
    public function index(
        Request $request,
        OrganizationRepository $organizationRepository,
        DashboardDataService $dashboardDataService,
    ): Response {
        $organizations = $organizationRepository->findAllParentOrganizations();

        // Начальные данные: первая организация или все
        $firstOrgId = !empty($organizations) ? $organizations[0]->getId() : 0;
        $scale = $this->normalizeScale($request->query->getString('scale', 'month'));
        $dashboardData = $dashboardDataService->getData($firstOrgId, $scale);

        return $this->render('analytics/dashboard/dashboard.html.twig', [
            'organizations' => $organizations,
            'initialScale' => $scale,
            'dashboardDataJson' => json_encode($dashboardData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    #[Route('/analytics/dashboard/data', name: 'app_analytics_dashboard_data', methods: ['GET'])]
    public function data(
        Request $request,
        DashboardDataService $dashboardDataService,
    ): JsonResponse {
        $orgId = $request->query->getInt('org_id', 0);
        $scale = $this->normalizeScale($request->query->getString('scale', 'month'));

        return $this->json($dashboardDataService->getData($orgId, $scale));
    }
}
