<?php

declare(strict_types=1);

namespace App\Controller\Analytics;

use App\Repository\Analytics\AnalyticsOrganizationRepository;
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

    /**
     * Легаси-дашборд собирает все разделы (включая финансы) по любой организации
     * из org_id и сравнение по всем видимым — профильным ролям (HR, механики…)
     * он давал бы чужие показатели в обход скоупа SPA-контроллеров (BE-08/09).
     * Поэтому вход только глобальным аналитикам и администраторам; профильные
     * роли работают в SPA-разделах со своим скоупом.
     */
    private function hasAnalyticsRole(): bool
    {
        return $this->isGranted('ROLE_ANALYTIC') || $this->isGranted('ROLE_ADMIN');
    }

    #[Route('/analytics/dashboard', name: 'app_analytics_dashboard')]
    public function index(
        Request $request,
        AnalyticsOrganizationRepository $analyticsOrganizationRepository,
        DashboardDataService $dashboardDataService,
    ): Response {
        // BE-09: легаси-дашборд отдавал агрегаты холдинга любому ROLE_USER.
        // Пускаем тех же, кому доступна SPA-аналитика (список ролей — OR).
        if (!$this->hasAnalyticsRole()) {
            throw $this->createAccessDeniedException('Недостаточно прав для просмотра аналитики.');
        }

        $analyticsOrganizations = $analyticsOrganizationRepository->findVisibleOrdered();
        $organizations = array_map(
            static fn ($analyticsOrganization) => $analyticsOrganization->getOrganization(),
            $analyticsOrganizations,
        );

        // Начальные данные: первая организация или все
        $firstOrgId = !empty($organizations) ? $organizations[0]->getId() : 0;
        $scale = $this->normalizeScale($request->query->getString('scale', 'month'));
        $dashboardData = $dashboardDataService->getData($firstOrgId, $scale);
        $dashboardData['compare'] = $dashboardDataService->getCompareData($firstOrgId, $scale);

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

        $data = $dashboardDataService->getData($orgId, $scale);
        $data['compare'] = $dashboardDataService->getCompareData($orgId, $scale);

        return $this->json($data);
    }

    #[Route('/analytics/dashboard/compare-data', name: 'app_analytics_dashboard_compare_data', methods: ['GET'])]
    public function compareData(
        Request $request,
        DashboardDataService $dashboardDataService,
    ): JsonResponse {
        $orgId = $request->query->getInt('org_id', 0);
        $scale = $this->normalizeScale($request->query->getString('scale', 'month'));
        $year = $request->query->getInt('year') ?: null;
        $period = $request->query->getInt('period') ?: null;

        return $this->json($dashboardDataService->getCompareData($orgId, $scale, $year, $period));
    }
}
