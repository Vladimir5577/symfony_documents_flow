<?php

namespace App\Repository\Inventory;

use App\Entity\Inventory\Warehouse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Warehouse>
 */
class WarehouseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Warehouse::class);
    }

    /**
     * @return array{warehouses: array<int, Warehouse>, total: int, page: int, limit: int, totalPages: int}
     */
    public function findPaginated(
        int $page = 1,
        int $limit = 10,
        string $search = '',
        ?int $departmentId = null,
        ?bool $isActive = null,
    ): array {
        $qb = $this->createQueryBuilder('w')
            ->leftJoin('w.department', 'department')
            ->addSelect('department')
            ->leftJoin('w.responsibleUser', 'responsible')
            ->addSelect('responsible')
            ->orderBy('w.name', 'ASC');

        $countQb = $this->createQueryBuilder('w')
            ->select('COUNT(w.id)');

        if ($search !== '') {
            $condition = 'LOWER(w.name) LIKE LOWER(:search)';
            $qb->andWhere($condition)->setParameter('search', '%' . $search . '%');
            $countQb->andWhere($condition)->setParameter('search', '%' . $search . '%');
        }

        if ($departmentId !== null) {
            $qb->andWhere('w.department = :departmentId')->setParameter('departmentId', $departmentId);
            $countQb->andWhere('w.department = :departmentId')->setParameter('departmentId', $departmentId);
        }

        if ($isActive !== null) {
            $qb->andWhere('w.isActive = :isActive')->setParameter('isActive', $isActive);
            $countQb->andWhere('w.isActive = :isActive')->setParameter('isActive', $isActive);
        }

        $total = (int) $countQb->getQuery()->getSingleScalarResult();
        $warehouses = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [
            'warehouses' => $warehouses,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $total > 0 ? (int) ceil($total / $limit) : 1,
        ];
    }

    /**
     * @return array<int, Warehouse>
     */
    public function findByResponsible(int $userId): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.responsibleUser = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('w.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
