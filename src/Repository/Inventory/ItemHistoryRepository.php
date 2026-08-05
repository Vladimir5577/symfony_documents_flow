<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\Item;
use App\Entity\Inventory\ItemHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ItemHistory>
 */
class ItemHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ItemHistory::class);
    }

    /**
     * @return ItemHistory[]
     */
    public function findByItem(Item $item): array
    {
        return $this->createQueryBuilder('h')
            ->addSelect('u', 'oa', 'na')
            ->leftJoin('h.changedBy', 'u')
            ->leftJoin('h.oldAssignedTo', 'oa')
            ->leftJoin('h.newAssignedTo', 'na')
            ->andWhere('h.item = :item')
            ->setParameter('item', $item)
            ->orderBy('h.createdAt', 'DESC')
            ->addOrderBy('h.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
