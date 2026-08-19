<?php

declare(strict_types=1);

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseApprovalStep;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseStepDecision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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
     * Число заявок, ждущих действия этого человека (бейдж в меню).
     *
     * Только активные шаги — те, что стоят на позиции указателя. Считать все
     * PENDING нельзя: будущие шаги пользователю ещё недоступны, и бейдж показал
     * бы работу, которую он сделать не может.
     *
     * @param list<string> $roleCodes роли модуля у пользователя (для ролевых шагов)
     */
    public function countActiveForUser(User $user, array $roleCodes): int
    {
        return (int) $this->activeForUserQb($user, $roleCodes)
            ->select('COUNT(DISTINCT s.purchaseRequest)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Шаг адресован пользователю: лично или через роль модуля.
     * Вынесено, чтобы условие «кто может» жило в одном месте.
     *
     * @param list<string> $roleCodes
     */
    private function activeForUserQb(User $user, array $roleCodes): QueryBuilder
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.decision = :pending')
            ->andWhere($this->addressedExpr($roleCodes))
            // Указатель: шаг стоит на минимальной незакрытой позиции своей заявки
            ->andWhere('s.position = (
                SELECT MIN(s2.position) FROM ' . PurchaseApprovalStep::class . ' s2
                WHERE s2.purchaseRequest = s.purchaseRequest AND s2.decision = :pending
            )')
            ->setParameter('pending', PurchaseStepDecision::PENDING)
            ->setParameter('user', $user);

        if ($roleCodes !== []) {
            $qb->setParameter('roleCodes', $roleCodes, ArrayParameterType::STRING);
        }

        return $qb;
    }

    /** @param list<string> $roleCodes */
    private function addressedExpr(array $roleCodes): string
    {
        return $roleCodes === []
            ? 's.approverUser = :user'
            : '(s.approverUser = :user OR s.roleCode IN (:roleCodes))';
    }
}
