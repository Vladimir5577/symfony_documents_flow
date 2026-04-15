<?php

declare(strict_types=1);

namespace App\Controller\Analytics;

use App\Entity\Analytics\AnalyticsAggregatedData;
use App\Entity\Analytics\AnalyticsBoard;
use App\Entity\Organization\AbstractOrganization;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AnalyticsChartController extends AbstractController
{
    #[Route('/analytics/chart', name: 'app_analytics_chart_show')]
    public function show(
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        $boardId = (int) $request->query->get('board_id', 0);
        $selectedBoardId = $boardId > 0 ? $boardId : 0;
        $periodIdVal = (int) $request->query->get('period_id', 0);
        $selectedPeriodId = $periodIdVal > 0 ? $periodIdVal : 0;
        $metricIdVal = (int) $request->query->get('metric_id', 0);
        $selectedMetricId = $metricIdVal > 0 ? $metricIdVal : 0;

        $organizations = $em->getRepository(AbstractOrganization::class)->findAll();
        $boards = $em->getRepository(AnalyticsBoard::class)->findAll();
        $periods = $em->getRepository(\App\Entity\Analytics\AnalyticsPeriod::class)->findBy([], ['startDate' => 'DESC']);

        $board = $selectedBoardId ? $em->find(AnalyticsBoard::class, $selectedBoardId) : null;

        // Данные: агрегированные значения по организациям и периодам
        $comparisonData = []; // [organization_name][metric_name] => value
        $trendData = [];      // [metric_name][organization_name][period_desc] => value

        if ($board) {
            // Забираем агрегаты через JOIN period → reports → board
            $qb = $em->getRepository(AnalyticsAggregatedData::class)->createQueryBuilder('a')
                ->join('a.period', 'p')
                ->join('p.boardReports', 'r')
                ->andWhere('r.board = :boardId')
                ->setParameter('boardId', $selectedBoardId)
                ->orderBy('p.startDate', 'DESC');

            $aggregated = $qb->getQuery()->getResult();

            if ($selectedPeriodId) {
                $aggregated = array_filter($aggregated, fn($item) => $item->getPeriod() && $item->getPeriod()->getId() === $selectedPeriodId);
            }

            foreach ($aggregated as $item) {
                $orgName = $item->getOrganization()->getName();
                $metricName = $item->getMetricNameSnapshot();
                $periodDesc = $item->getPeriod() ? $item->getPeriod()->getDescription() : '?';

                // Тренд: все данные
                $trendData[$metricName][$orgName][$periodDesc] = $item->getValue();

                // Сравнение: только если период не выбран или это последний эффективный
                if (!isset($comparisonData[$orgName][$metricName]) || ($selectedPeriodId && $item->getPeriod() && $item->getPeriod()->getId() === $selectedPeriodId)) {
                    $comparisonData[$orgName][$metricName] = $item->getValue();
                }
            }
        }

        return $this->render('analytics/chart/show.html.twig', [
            'boards' => $boards,
            'organizations' => $organizations,
            'periods' => $periods,
            'board' => $board,
            'selectedBoardId' => $selectedBoardId,
            'selectedPeriodId' => $selectedPeriodId,
            'selectedMetricId' => $selectedMetricId,
            'comparisonData' => $comparisonData,
            'trendData' => $trendData,
            'active_tab' => 'analytics_chart',
        ]);
    }
}
