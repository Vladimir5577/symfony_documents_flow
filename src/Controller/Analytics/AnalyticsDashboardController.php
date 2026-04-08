<?php

declare(strict_types=1);

namespace App\Controller\Analytics;

use App\Entity\Analytics\AnalyticsBoard;
use App\Entity\Analytics\AnalyticsOrganizationBoard;
use App\Entity\Organization\AbstractOrganization;
use App\Repository\Analytics\AnalyticsPeriodRepository;
use App\Service\Analytics\GetDashboardDataService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AnalyticsDashboardController extends AbstractController
{
    #[Route('/analytics/dashboard', name: 'app_analytics_dashboard')]
    public function index(
        Request $request,
        GetDashboardDataService $dashboardService,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $organization = $user->getOrganization();

        // Получаем все доски
        $boards = $em->getRepository(AnalyticsBoard::class)->findAll();

        // Если организация — только назначенные ей доски
        $userBoards = [];
        $userOrgBoards = [];
        $orgId = $organization ? $organization->getId() : null;

        if ($organization) {
            $orgBoards = $em->getRepository(AnalyticsOrganizationBoard::class)->findBy(['organization' => $organization]);
            foreach ($orgBoards as $ob) {
                $userBoards[] = $ob->getBoard();
            }
        } else {
            // Если нет organization — показываем все доски
            $userBoards = $boards;
        }

        // Периоды для фильтра
        $periodRepo = $em->getRepository(\App\Entity\Analytics\AnalyticsPeriod::class);
        $periods = $periodRepo->findBy([], ['startDate' => 'DESC']);

        // Выбранный board и period из запроса
        $boardId = (int) $request->query->get('board_id', 0);
        $boardId = $boardId > 0 ? $boardId : 0;
        $periodId = (int) $request->query->get('period_id', 0);
        $periodId = $periodId > 0 ? $periodId : 0;

        // Если период вообще не передан (нет в URL) — берём последний доступный
        // Если period_id=0 ("все периоды") — не трогаем
        $periodParam = $request->query->get('period_id', '');
        if ($boardId && $periodParam === '' && count($periods) > 0) {
            $periodId = reset($periods)->getId();
        }

        $dashboardData = null;
        if ($boardId) {
            try {
                $dashboardData = $dashboardService->getDashboardData(
                    $boardId,
                    $orgId,
                    $periodId ?: null
                );
            } catch (\Throwable $e) {
                // Если данных ещё нет
            }
        }

        // Статистика по организациям
        $stats = [];
        foreach ($periods as $period) {
            $reportsCount = $em->getRepository(\App\Entity\Analytics\AnalyticsReport::class)->createQueryBuilder('r')
                ->select('COUNT(r.id)')
                ->where('r.period = :period')
                ->setParameter('period', $period)
                ->getQuery()
                ->getSingleScalarResult();

            if ($reportsCount > 0) {
                $stats[] = [
                    'period' => $period,
                    'total' => (int) $reportsCount,
                ];
            }
        }

        return $this->render('analytics/dashboard/index.html.twig', [
            'boards' => $boards,
            'userBoards' => $userBoards,
            'periods' => $periods,
            'selectedBoardId' => $boardId,
            'selectedPeriodId' => $periodId,
            'dashboardData' => $dashboardData,
            'stats' => $stats,
            'active_tab' => 'analytics_dashboard',
        ]);
    }
}
