<?php

declare(strict_types=1);

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseApprovalTask;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseStageStatus;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\Purchase\PurchaseTaskAssignment;
use App\Enum\Purchase\PurchaseTaskDecision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseApprovalTask>
 */
class PurchaseApprovalTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseApprovalTask::class);
    }

    /**
     * Число заявок, ждущих действия этого человека (бейдж в меню).
     *
     * Только задачи активного этапа. Считать все PENDING нельзя: задачи будущих
     * этапов пользователю ещё недоступны, и бейдж показал бы работу, которую он
     * сделать не может.
     *
     * Прежде «активность» приходилось выражать подзапросом с MIN(position) —
     * указатель нигде не хранился. Теперь это условие по статусу этапа.
     *
     * @param list<string> $roleCodes роли модуля у пользователя (для ролевых задач)
     */
    public function countActiveForUser(User $user, array $roleCodes): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(DISTINCT s.purchaseRequest)')
            ->innerJoin('t.stage', 's')
            ->innerJoin('s.purchaseRequest', 'p')
            ->andWhere('t.decision = :pending')
            ->andWhere('s.status = :active')
            ->andWhere('p.status = :onApproval')
            ->andWhere($this->addressedExpr($roleCodes))
            ->setParameter('pending', PurchaseTaskDecision::PENDING)
            ->setParameter('active', PurchaseStageStatus::ACTIVE)
            ->setParameter('onApproval', PurchaseStatus::ON_APPROVAL)
            ->setParameter('author', PurchaseTaskAssignment::AUTHOR)
            ->setParameter('user', $user);

        if ($roleCodes !== []) {
            $qb->setParameter('roleCodes', $roleCodes, ArrayParameterType::STRING);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Задача адресована пользователю: лично, через роль модуля или как автору
     * заявки. Условие живёт в одном месте, потому что его спрашивают и бейдж, и
     * очередь разбора, и список «я согласант».
     *
     * @param list<string> $roleCodes
     */
    private function addressedExpr(array $roleCodes): string
    {
        $personal = 't.assigneeUser = :user OR (t.assignmentType = :author AND p.createdBy = :user)';

        return $roleCodes === []
            ? '(' . $personal . ')'
            : '(' . $personal . ' OR t.roleCode IN (:roleCodes))';
    }
}
