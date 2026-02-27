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
     * Доски, в которых пользователь является участником.
     *
     * @return KanbanBoard[]
     */
    public function findByMember(User $user): array
    {
        return $this->createQueryBuilder('b')
            ->innerJoin('b.members', 'm')
            ->where('m.user = :user')
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
            ->leftJoin('b.columns', 'col')->addSelect('col')
            ->leftJoin('col.cards', 'card')->addSelect('card')
            ->leftJoin('card.labels', 'lbl')->addSelect('lbl')
            ->leftJoin('card.checklistItems', 'ci')->addSelect('ci')
            ->leftJoin('b.members', 'mem')->addSelect('mem')
            ->leftJoin('mem.user', 'mu')->addSelect('mu')
            ->where('b.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
