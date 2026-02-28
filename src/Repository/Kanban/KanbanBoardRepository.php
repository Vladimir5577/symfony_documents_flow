<?php

namespace App\Repository\Kanban;

use App\Entity\Kanban\KanbanBoard;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KanbanBoard>
 */
class KanbanBoardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KanbanBoard::class);
    }

    /**
     * Доски проектов, в которых пользователь является владельцем или участником.
     *
     * @return KanbanBoard[]
     */
    public function findByMember(User $user): array
    {
        return $this->createQueryBuilder('b')
            ->innerJoin('b.project', 'p')
            ->leftJoin('App\Entity\Kanban\Project\KanbanProjectUser', 'pu', 'WITH', 'pu.kanbanProject = p AND pu.user = :user')
            ->where('p.owner = :user')
            ->orWhere('pu.id IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('b.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Доска со всеми связями (колонки, карточки, чеклист, метки).
     */
    public function findOneWithRelations(int $id): ?KanbanBoard
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.project', 'p')->addSelect('p')
            ->leftJoin('b.columns', 'col')->addSelect('col')
            ->leftJoin('col.cards', 'card')->addSelect('card')
            ->leftJoin('card.labels', 'lbl')->addSelect('lbl')
            ->leftJoin('card.checklistItems', 'ci')->addSelect('ci')
            ->where('b.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
