<?php

namespace App\Repository\Analytics;

use App\Entity\Analytics\AnalyticsBoard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsBoard>
 */
class AnalyticsBoardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsBoard::class);
    }


}
