<?php

namespace App\Repository\Analytics;

use App\Entity\Analytics\AnalyticsBoardVersionMetric;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsBoardVersionMetric>
 */
class AnalyticsBoardVersionMetricRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsBoardVersionMetric::class);
    }


}
