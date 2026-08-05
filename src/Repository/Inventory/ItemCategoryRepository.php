<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\ItemCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ItemCategory>
 */
class ItemCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ItemCategory::class);
    }

    /**
     * @return ItemCategory[]
     */
    public function findAllOrdered(bool $onlyActive = false): array
    {
        $qb = $this->createQueryBuilder('c')->orderBy('c.name', 'ASC');

        if ($onlyActive) {
            $qb->andWhere('c.isActive = true');
        }

        return $qb->getQuery()->getResult();
    }
}
