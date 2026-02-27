<?php

namespace App\Repository\Kanban;

use App\Entity\Kanban\KanbanBoard;
use App\Entity\Kanban\KanbanBoardMember;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KanbanBoardMember>
 */
class KanbanBoardMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KanbanBoardMember::class);
    }

    public function findByBoardAndUser(KanbanBoard $board, User $user): ?KanbanBoardMember
    {
        return $this->createQueryBuilder('m')
            ->where('m.board = :board')
            ->andWhere('m.user = :user')
            ->setParameter('board', $board)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
