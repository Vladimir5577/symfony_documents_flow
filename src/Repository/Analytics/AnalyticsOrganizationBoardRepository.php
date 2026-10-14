<?php

namespace App\Repository\Analytics;

use App\Entity\Analytics\AnalyticsOrganizationBoard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsOrganizationBoard>
 */
class AnalyticsOrganizationBoardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsOrganizationBoard::class);
    }


}
