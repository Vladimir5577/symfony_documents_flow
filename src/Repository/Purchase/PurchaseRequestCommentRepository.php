<?php

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseRequestComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseRequestComment>
 */
class PurchaseRequestCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseRequestComment::class);
    }

    /**
     * @return array{items: list<PurchaseRequestComment>, total: int}
     */
    public function findPageByPurchase(int $purchaseId, int $page, int $pageSize): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('IDENTITY(c.purchaseRequest) = :id')
            ->setParameter('id', $purchaseId);

        $total = (int) (clone $qb)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();

        $items = $qb
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }
}
