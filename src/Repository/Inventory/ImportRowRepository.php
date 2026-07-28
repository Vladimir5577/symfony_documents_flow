<?php

namespace App\Repository\Inventory;

use App\Entity\Inventory\ImportBatch;
use App\Entity\Inventory\ImportRow;
use App\Enum\Inventory\ImportRowStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportRow>
 */
class ImportRowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportRow::class);
    }

    /**
     * Страница строк батча. Фильтр, счётчик и срез — в SQL: в выгрузке владельца
     * 2184 строки, и поднимать их все ради пятидесяти на экране незачем.
     *
     * @return array{rows: array<int, ImportRow>, total: int}
     */
    public function findPageForBatch(
        ImportBatch $batch,
        ?ImportRowStatus $status,
        int $page,
        int $limit,
    ): array {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.matchedNomenclature', 'n')
            ->addSelect('n')
            ->leftJoin('r.matchedUser', 'u')
            ->addSelect('u')
            ->where('r.batch = :batch')
            ->setParameter('batch', $batch)
            ->orderBy('r.rowNo', 'ASC')
            ->addOrderBy('r.id', 'ASC');

        if ($status !== null) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        $countQb = clone $qb;
        $total = (int) $countQb
            ->select('COUNT(r.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $rows = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['rows' => $rows, 'total' => $total];
    }
}
