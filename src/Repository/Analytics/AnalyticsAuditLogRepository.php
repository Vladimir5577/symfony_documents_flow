<?php

declare(strict_types=1);

namespace App\Repository\Analytics;

use App\Entity\Analytics\AnalyticsAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsAuditLog>
 */
class AnalyticsAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsAuditLog::class);
    }
}
