<?php

namespace App\Repository\Inventory;

use App\Entity\Inventory\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * @return array{categories: array<int, Category>, total: int, page: int, limit: int, totalPages: int}
     */
    public function findPaginated(int $page = 1, int $limit = 10, string $search = ''): array
    {
        $qb = $this->createQueryBuilder('c')->orderBy('c.sort', 'ASC')->addOrderBy('c.name', 'ASC');
        if ($search !== '') {
            $qb->andWhere('LOWER(c.name) LIKE LOWER(:search)')->setParameter('search', '%' . $search . '%');
        }

        $total = (int) (clone $qb)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

        return [
            'categories' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $total > 0 ? (int) ceil($total / $limit) : 1,
        ];
    }
}
