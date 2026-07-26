<?php

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseRequestApprover;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseRequestApprover>
 */
class PurchaseRequestApproverRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseRequestApprover::class);
    }

    /** Сколько приглашений ждут подтверждения пользователя (заявка ещё на рассмотрении). */
    public function countPendingForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->join('a.purchaseRequest', 'pr')
            ->andWhere('a.user = :user')
            ->andWhere('a.confirmedAt IS NULL')
            ->andWhere('pr.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', PurchaseStatus::PENDING_REVIEW)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
