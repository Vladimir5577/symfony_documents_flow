<?php

namespace App\Repository\Analytics;

use App\Entity\Analytics\AnalyticsBoardVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsBoardVersion>
 */
class AnalyticsBoardVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsBoardVersion::class);
    }


}
