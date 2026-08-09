<?php

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestApprover;
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
     * @param int|null                  $approverUserId  только заявки, где пользователь — приглашённый согласант
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
    ): array {
        $qb = $this->createFilteredQueryBuilder($createdById, $statuses, $search, $minAmount);

        if ($approverUserId !== null) {
            $qb->join(PurchaseRequestApprover::class, 'ap', 'WITH', 'ap.purchaseRequest = pr')
                ->andWhere('ap.user = :approverUserId')
                ->setParameter('approverUserId', $approverUserId);
        }

        $total = (int) (clone $qb)
            ->select('COUNT(pr.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Связи «к одному» подтягиваем сразу. Без этого презентер на каждой
        // строке лениво дёргал организацию (плюс обход её родителей для
        // полного пути), категорию, автора, исполнителя и должности обоих —
        // на странице в сто записей это сотни отдельных запросов.
        //
        // Коллекцию позиций тут фетчить нельзя: соединение «к многим» ломает
        // setMaxResults, страница поехала бы. Количество позиций и сумму
        // считает отдельный агрегирующий запрос — sumAndCountItemsByRequestIds.
        $items = $qb
            ->addSelect("CASE WHEN pr.priority = 'URGENT' THEN 0 ELSE 1 END AS HIDDEN prioritySort")
            ->leftJoin('pr.organization', 'org')->addSelect('org')
            ->leftJoin('pr.category', 'cat')->addSelect('cat')
            ->leftJoin('pr.createdBy', 'author')->addSelect('author')
            ->leftJoin('author.worker', 'authorWorker')->addSelect('authorWorker')
            ->leftJoin('pr.executor', 'executor')->addSelect('executor')
            ->leftJoin('executor.worker', 'executorWorker')->addSelect('executorWorker')
            ->orderBy('prioritySort', 'ASC')
            ->addOrderBy('pr.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Количество позиций и их сумма по списку заявок — одним запросом.
     *
     * Нужно, чтобы список не загружал коллекцию позиций у каждой заявки ради
     * getTotalAmount() и count(): именно это давало основной вес страницы.
     *
     * @param list<int> $requestIds
     *
     * @return array<int, array{count: int, total: float}> ключ — id заявки
     */
    public function sumAndCountItemsByRequestIds(array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()->createQuery(
            'SELECT IDENTITY(i.purchaseRequest) AS requestId,
                    COUNT(i.id) AS itemsCount,
                    COALESCE(SUM(i.quantity * i.estimatedPrice), 0) AS totalAmount
               FROM App\Entity\Purchase\PurchaseRequestItem i
              WHERE i.purchaseRequest IN (:ids)
              GROUP BY i.purchaseRequest'
        )
            ->setParameter('ids', $requestIds)
            ->getArrayResult();

        $aggregates = [];
        foreach ($rows as $row) {
            $aggregates[(int) $row['requestId']] = [
                'count' => (int) $row['itemsCount'],
                'total' => round((float) $row['totalAmount'], 2),
            ];
        }

        return $aggregates;
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
