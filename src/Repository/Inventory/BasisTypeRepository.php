<?php

namespace App\Repository\Inventory;

use App\Entity\Inventory\BasisType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BasisType>
 */
class BasisTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BasisType::class);
    }

    /**
     * @return array{basis_types: array<int, BasisType>, total: int, page: int, limit: int, totalPages: int}
     */
    public function findPaginated(int $page = 1, int $limit = 10, string $search = ''): array
    {
        $qb = $this->createQueryBuilder('b')->orderBy('b.sort', 'ASC')->addOrderBy('b.name', 'ASC');
        if ($search !== '') {
            $qb->andWhere('LOWER(b.name) LIKE LOWER(:search)')->setParameter('search', '%' . $search . '%');
        }

        $total = (int) (clone $qb)->select('COUNT(b.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

        return [
            'basis_types' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $total > 0 ? (int) ceil($total / $limit) : 1,
        ];
    }
}
