<?php

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseRequestHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseRequestHistory>
 */
class PurchaseRequestHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseRequestHistory::class);
    }
}
