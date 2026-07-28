<?php

namespace App\Repository\Inventory;

use App\Entity\Inventory\Reconciliation;
use App\Entity\Inventory\ReconciliationLine;
use App\Enum\Inventory\ComparisonStatus;
use App\Enum\Inventory\ResolutionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReconciliationLine>
 */
class ReconciliationLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReconciliationLine::class);
    }

    /**
     * Страница строк сверки с фильтрами. Фильтр и срез — в SQL, под индекс
     * `idx_inv_recon_line_recon`: сверка по выгрузке владельца — это 2184 строки,
     * и поднимать их все ради одной страницы незачем.
     *
     * @return array{rows: array<int, ReconciliationLine>, total: int}
     */
    public function findPageForReconciliation(
        Reconciliation $reconciliation,
        ?ComparisonStatus $comparison,
        ?ResolutionStatus $resolution,
        int $page,
        int $limit,
    ): array {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.nomenclature', 'n')
            ->addSelect('n')
            ->where('l.reconciliation = :reconciliation')
            ->setParameter('reconciliation', $reconciliation)
            ->orderBy('l.id', 'ASC');

        if ($comparison !== null) {
            $qb->andWhere('l.comparisonStatus = :comparison')->setParameter('comparison', $comparison);
        }
        if ($resolution !== null) {
            $qb->andWhere('l.resolutionStatus = :resolution')->setParameter('resolution', $resolution);
        }

        $countQb = clone $qb;
        $total = (int) $countQb
            ->select('COUNT(l.id)')
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

    /**
     * Сводка шапки экрана: счётчики одним группирующим запросом вместо обхода всех строк.
     *
     * ⚠ Разрезы разные и пересекаются: `ok/qty_mismatch/missing_in_system/missing_in_1c`
     * считаются по `comparison_status`, `explained/to_clarify` — по `resolution_status`.
     * Складывать их в одну сумму нельзя.
     *
     * @return array<string, int>
     */
    public function summaryForReconciliation(Reconciliation $reconciliation): array
    {
        $summary = [
            'total' => 0,
            'ok' => 0,
            'qty_mismatch' => 0,
            'missing_in_system' => 0,
            'missing_in_1c' => 0,
            'explained' => 0,
            'to_clarify' => 0,
        ];

        $rows = $this->createQueryBuilder('l')
            ->select('l.comparisonStatus AS comparison', 'l.resolutionStatus AS resolution', 'COUNT(l.id) AS cnt')
            ->where('l.reconciliation = :reconciliation')
            ->setParameter('reconciliation', $reconciliation)
            ->groupBy('l.comparisonStatus')
            ->addGroupBy('l.resolutionStatus')
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $count = (int) $row['cnt'];
            $summary['total'] += $count;

            // Doctrine отдаёт enum-поле либо объектом, либо сырой строкой — зависит
            // от режима гидрации, поэтому приводим оба варианта к value.
            $comparison = $row['comparison'] ?? null;
            if ($comparison !== null) {
                $key = $comparison instanceof ComparisonStatus ? $comparison->value : (string) $comparison;
                if (\array_key_exists($key, $summary)) {
                    $summary[$key] += $count;
                }
            }

            $resolution = $row['resolution'] ?? null;
            $resolutionKey = $resolution instanceof ResolutionStatus ? $resolution->value : (string) $resolution;
            if ($resolutionKey === ResolutionStatus::EXPLAINED->value) {
                $summary['explained'] += $count;
            } elseif ($resolutionKey === ResolutionStatus::TO_CLARIFY->value) {
                $summary['to_clarify'] += $count;
            }
        }

        return $summary;
    }
}
