<?php

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseRequest;
use App\Enum\Purchase\PurchaseStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseRequest>
 */
class PurchaseRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseRequest::class);
    }

    /**
     * Список с фильтрами и пагинацией. Срочные — сверху, затем новые.
     *
     * @param list<int>|null            $organizationIds ограничение по узлам организаций (null = без ограничения)
     * @param list<PurchaseStatus>|null $statuses        ограничение по статусам (null = все)
     * @return array{items: list<PurchaseRequest>, total: int}
     */
    public function findByFilters(
        ?array $organizationIds,
        ?array $statuses,
        ?string $search,
        int $page,
        int $pageSize,
    ): array {
        $qb = $this->createFilteredQueryBuilder($organizationIds, $statuses, $search);

        $total = (int) (clone $qb)
            ->select('COUNT(pr.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $qb
            ->addSelect("CASE WHEN pr.priority = 'URGENT' THEN 0 ELSE 1 END AS HIDDEN prioritySort")
            ->orderBy('prioritySort', 'ASC')
            ->addOrderBy('pr.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Количество заявок по каждому статусу (для счётчиков-бейджей).
     *
     * @param list<int>|null $organizationIds
     * @return array<string, int> [status value => count]
     */
    public function countByStatuses(?array $organizationIds): array
    {
        $qb = $this->createFilteredQueryBuilder($organizationIds, null, null)
            ->select('pr.status AS status, COUNT(pr.id) AS cnt')
            ->groupBy('pr.status');

        $counts = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $status = $row['status'] instanceof PurchaseStatus ? $row['status']->value : (string) $row['status'];
            $counts[$status] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * @param list<int>|null            $organizationIds
     * @param list<PurchaseStatus>|null $statuses
     */
    private function createFilteredQueryBuilder(?array $organizationIds, ?array $statuses, ?string $search): QueryBuilder
    {
        $qb = $this->createQueryBuilder('pr');

        if ($organizationIds !== null) {
            $qb->andWhere('pr.organization IN (:organizationIds)')
                ->setParameter('organizationIds', $organizationIds);
        }

        if ($statuses !== null) {
            $qb->andWhere('pr.status IN (:statuses)')
                ->setParameter('statuses', $statuses);
        }

        if ($search !== null && $search !== '') {
            if (ctype_digit($search)) {
                $qb->andWhere('pr.id = :searchId OR LOWER(pr.title) LIKE :search')
                    ->setParameter('searchId', (int) $search);
            } else {
                $qb->andWhere('LOWER(pr.title) LIKE :search');
            }
            $qb->setParameter('search', '%' . mb_strtolower($search) . '%');
        }

        return $qb;
    }
}
