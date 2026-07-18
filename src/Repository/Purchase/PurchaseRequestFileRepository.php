<?php

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseRequestFile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseRequestFile>
 */
class PurchaseRequestFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseRequestFile::class);
    }
}
