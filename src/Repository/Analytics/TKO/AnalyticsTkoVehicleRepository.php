<?php

declare(strict_types=1);

namespace App\Repository\Analytics\TKO;

use App\Entity\Analytics\TKO\AnalyticsTkoVehicle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsTkoVehicle>
 */
class AnalyticsTkoVehicleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsTkoVehicle::class);
    }

    /**
     * @param array{organizationId?: int|null, polygonId?: int|null} $filters
     *
     * @return array{items: AnalyticsTkoVehicle[], total: int, page: int, limit: int, totalPages: int}
     */
    public function findPage(int $page, int $limit, array $filters = []): array
    {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $countQb = $this->createQueryBuilder('v')->select('COUNT(v.id)');
        $this->applyFilters($countQb, $filters);
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $totalPages = $total > 0 ? (int) ceil($total / $limit) : 1;
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $itemsQb = $this->createQueryBuilder('v')
            ->innerJoin('v.polygon', 'p')->addSelect('p')
            ->innerJoin('v.organization', 'o')->addSelect('o')
            ->orderBy('v.isActive', 'DESC')
            ->addOrderBy('v.licenseNumber', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);
        $this->applyFilters($itemsQb, $filters);

        return [
            'items' => $itemsQb->getQuery()->getResult(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * @param array{organizationId?: int|null, polygonId?: int|null} $filters
     */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        $organizationId = $filters['organizationId'] ?? null;
        if (null !== $organizationId && $organizationId > 0) {
            $qb->andWhere('v.organization = :filterOrganization')
                ->setParameter('filterOrganization', $organizationId);
        }

        $polygonId = $filters['polygonId'] ?? null;
        if (null !== $polygonId && $polygonId > 0) {
            $qb->andWhere('v.polygon = :filterPolygon')
                ->setParameter('filterPolygon', $polygonId);
        }
    }
}
