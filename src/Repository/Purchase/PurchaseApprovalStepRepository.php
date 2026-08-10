<?php

declare(strict_types=1);

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseApprovalStep;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseStepDecision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseApprovalStep>
 */
class PurchaseApprovalStepRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseApprovalStep::class);
    }

    /**
     * Шаги, по которым пользователь может действовать ПРЯМО СЕЙЧАС.
     *
     * Только активные — те, что стоят на позиции указателя. Считать все PENDING
     * нельзя: будущие шаги пользователю ещё недоступны, и бейдж показал бы
     * работу, которую он сделать не может.
     *
     * @param list<string> $roles роли пользователя (для ролевых шагов)
     * @return list<PurchaseApprovalStep>
     */
    public function findActiveForUser(User $user, array $roles): array
    {
        return $this->activeForUserQb($user, $roles)
            ->addSelect('r')
            ->leftJoin('s.purchaseRequest', 'r')
            ->getQuery()
            ->getResult();
    }

    /** Число заявок, ждущих действия этого человека (бейдж в меню). */
    public function countActiveForUser(User $user, array $roles): int
    {
        return (int) $this->activeForUserQb($user, $roles)
            ->select('COUNT(DISTINCT s.purchaseRequest)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Id заявок, где пользователь есть в маршруте на любом шаге —
     * для фильтра «Я согласант» и для проверки доступа на чтение.
     *
     * @param list<string> $roles
     * @return list<int>
     */
    public function findRequestIdsForParticipant(User $user, array $roles): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('DISTINCT IDENTITY(s.purchaseRequest) AS request_id')
            ->andWhere($this->addressedExpr($roles))
            ->setParameter('user', $user);

        if ($roles !== []) {
            $qb->setParameter('roles', $roles);
        }

        return array_map(
            static fn (array $row): int => (int) $row['request_id'],
            $qb->getQuery()->getResult(),
        );
    }

    /**
     * Шаг адресован пользователю: лично или через роль.
     * Вынесено, чтобы условие «кто может» жило в одном месте.
     *
     * @param list<string> $roles
     */
    private function activeForUserQb(User $user, array $roles): QueryBuilder
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.decision = :pending')
            ->andWhere($this->addressedExpr($roles))
            // Указатель: шаг стоит на минимальной незакрытой позиции своей заявки
            ->andWhere('s.position = (
                SELECT MIN(s2.position) FROM ' . PurchaseApprovalStep::class . ' s2
                WHERE s2.purchaseRequest = s.purchaseRequest AND s2.decision = :pending
            )')
            ->setParameter('pending', PurchaseStepDecision::PENDING)
            ->setParameter('user', $user);

        if ($roles !== []) {
            $qb->setParameter('roles', $roles);
        }

        return $qb;
    }

    /** @param list<string> $roles */
    private function addressedExpr(array $roles): string
    {
        return $roles === []
            ? 's.approverUser = :user'
            : '(s.approverUser = :user OR s.approverRole IN (:roles))';
    }
}
