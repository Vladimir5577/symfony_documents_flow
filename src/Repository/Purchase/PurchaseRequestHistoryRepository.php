<?php

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseRequestHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseRequestHistory>
 */
class PurchaseRequestHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseRequestHistory::class);
    }

    /**
     * @return array{items: list<PurchaseRequestHistory>, total: int}
     */
    public function findPageByPurchase(int $purchaseId, int $page, int $pageSize): array
    {
        $qb = $this->createQueryBuilder('h')
            ->andWhere('IDENTITY(h.purchaseRequest) = :id')
            ->setParameter('id', $purchaseId);

        $total = (int) (clone $qb)->select('COUNT(h.id)')->getQuery()->getSingleScalarResult();

        $items = $qb
            ->orderBy('h.createdAt', 'DESC')
            ->addOrderBy('h.id', 'DESC')
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }
}
