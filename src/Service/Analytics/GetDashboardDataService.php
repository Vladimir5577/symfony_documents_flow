<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsBoard;
use App\Repository\Analytics\AnalyticsAggregatedDataRepository;
use Doctrine\ORM\EntityManagerInterface;

final class GetDashboardDataService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnalyticsAggregatedDataRepository $aggregatedDataRepository,
    ) {
    }

    /**
     * Получить данные дашборда для организации и доски.
     *
     * @param int $boardId ID доски
     * @param int|null $orgId ID организации (null — все организации)
     * @param int|null $periodId ID периода (null — последний)
     * @return array{
     *   board: AnalyticsBoard,
     *   metrics: array<array{name: string, unit: string, value?: mixed}>
     * }
     */
    public function getDashboardData(int $boardId, ?int $orgId = null, ?int $periodId = null): array
    {
        $board = $this->em->find(AnalyticsBoard::class, $boardId);
        if (!$board) {
            throw new \RuntimeException('Доска не найдена.');
        }

        $qb = $this->aggregatedDataRepository->createQueryBuilder('a')
            ->join('a.period', 'p')
            ->join('p.boardReports', 'r')
            ->andWhere('r.board = :boardId')
            ->setParameter('boardId', $boardId);

        if ($orgId) {
            $qb->andWhere('a.organization = :orgId')
               ->setParameter('orgId', $orgId);
        }
        if ($periodId) {
            $qb->andWhere('a.period = :periodId')
               ->setParameter('periodId', $periodId);
        }

        $qb->orderBy('p.startDate', 'DESC');
        $data = $qb->getQuery()->getResult();

        $metrics = [];
        foreach ($data as $item) {
            $metrics[] = [
                'name' => $item->getMetricNameSnapshot(),
                'unit' => $item->getMetricUnitSnapshot(),
                'value' => $item->getValue(),
            ];
        }

        return [
            'board' => $board,
            'metrics' => $metrics,
        ];
    }
}
