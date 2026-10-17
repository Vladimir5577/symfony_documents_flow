<?php

namespace App\Repository\Analytics;

use App\Entity\Analytics\AnalyticsPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsPeriod>
 */
class AnalyticsPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsPeriod::class);
    }


}
