<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\ItemCategory;
use App\Service\Inventory\CategoryTree;
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
     * Весь справочник одним скалярным запросом, без гидрации сущностей.
     */
    public function loadTree(): CategoryTree
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'IDENTITY(c.parent) AS parentId', 'c.name', 'c.isActive')
            ->getQuery()
            ->getArrayResult();

        $nodes = [];
        foreach ($rows as $row) {
            $nodes[(int) $row['id']] = [
                'parentId' => $row['parentId'] !== null ? (int) $row['parentId'] : null,
                'name' => (string) $row['name'],
                'isActive' => (bool) $row['isActive'],
            ];
        }

        return new CategoryTree($nodes);
    }

    /**
     * Занято ли имя среди соседей — без учёта регистра, чтобы «Мониторы» и «мониторы»
     * не завелись рядом. Уникальный индекс в базе регистрозависимый и ловит только
     * точный дубль.
     */
    public function findSibling(string $name, ?ItemCategory $parent, ?int $exceptId = null): ?ItemCategory
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('LOWER(c.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->setMaxResults(1);

        if ($parent === null) {
            $qb->andWhere('c.parent IS NULL');
        } else {
            $qb->andWhere('c.parent = :parent')->setParameter('parent', $parent);
        }

        if ($exceptId !== null) {
            $qb->andWhere('c.id <> :exceptId')->setParameter('exceptId', $exceptId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}
