<?php

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseApprovalStep;
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
     * @param int|null                  $createdById    только заявки этого автора (null = без ограничения)
     * @param list<PurchaseStatus>|null $statuses        ограничение по статусам (null = все)
     * @param int|null                  $approverUserId  только заявки, где пользователь есть в маршруте
     * @param list<string>              $approverRoles   его роли — для ролевых шагов маршрута
     * @param float|null                $minAmount       скрыть заявки дешевле порога (сумма считается из позиций)
     * @return array{items: list<PurchaseRequest>, total: int}
     */
    public function findByFilters(
        ?int $createdById,
        ?array $statuses,
        ?string $search,
        int $page,
        int $pageSize,
        ?int $approverUserId = null,
        ?float $minAmount = null,
        array $approverRoles = [],
    ): array {
        $qb = $this->createFilteredQueryBuilder($createdById, $statuses, $search, $minAmount);

        // «Я согласант» — заявки, где человек есть в маршруте: лично или через роль.
        if ($approverUserId !== null) {
            $qb->join(PurchaseApprovalStep::class, 'st', 'WITH', 'st.purchaseRequest = pr')
                ->setParameter('approverUserId', $approverUserId)
                ->distinct();

            if ($approverRoles === []) {
                $qb->andWhere('st.approverUser = :approverUserId');
            } else {
                $qb->andWhere('st.approverUser = :approverUserId OR st.approverRole IN (:approverRoles)')
                    ->setParameter('approverRoles', $approverRoles);
            }
        }

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
     * @param int|null $createdById
     * @return array<string, int> [status value => count]
     */
    public function countByStatuses(?int $createdById): array
    {
        $qb = $this->createFilteredQueryBuilder($createdById, null, null)
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
     * @param int|null                  $createdById
     * @param list<PurchaseStatus>|null $statuses
     */
    private function createFilteredQueryBuilder(
        ?int $createdById,
        ?array $statuses,
        ?string $search,
        ?float $minAmount = null,
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('pr');

        if ($minAmount !== null) {
            // Сумма не хранится в колонке (считается из позиций) — фильтруем скалярным подзапросом,
            // чтобы клон под COUNT(pr.id) работал без группировок.
            $qb->andWhere(
                '(SELECT COALESCE(SUM(i.quantity * i.estimatedPrice), 0)
                  FROM App\Entity\Purchase\PurchaseRequestItem i
                  WHERE i.purchaseRequest = pr) >= :minAmount'
            )->setParameter('minAmount', $minAmount);
        }

        if ($createdById !== null) {
            $qb->andWhere('pr.createdBy = :createdById')
                ->setParameter('createdById', $createdById);
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
