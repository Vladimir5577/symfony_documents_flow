<?php

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseCategoryItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseCategoryItem>
 */
class PurchaseCategoryItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseCategoryItem::class);
    }
}
