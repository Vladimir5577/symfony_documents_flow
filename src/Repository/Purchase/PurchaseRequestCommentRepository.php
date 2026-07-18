<?php

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseRequestComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseRequestComment>
 */
class PurchaseRequestCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseRequestComment::class);
    }
}
