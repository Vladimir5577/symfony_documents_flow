<?php

declare(strict_types=1);

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseApprover;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseApprover>
 */
class PurchaseApproverRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseApprover::class);
    }

    /**
     * @return list<PurchaseApprover>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /** Новый согласант встаёт в конец списка. */
    public function nextPosition(): int
    {
        $max = $this->createQueryBuilder('a')
            ->select('MAX(a.position)')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $max + 1;
    }
}
