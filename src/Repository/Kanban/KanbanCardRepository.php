<?php

namespace App\Repository\Kanban;

use App\Entity\Kanban\KanbanCard;
use App\Entity\Kanban\KanbanColumn;
use App\Entity\Kanban\Project\KanbanProject;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KanbanCard>
 */
class KanbanCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KanbanCard::class);
    }

    public function getMaxPosition(KanbanColumn $column): float
    {
        $result = $this->createQueryBuilder('c')
            ->select('MAX(c.position)')
            ->where('c.column = :column')
            ->setParameter('column', $column)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0.0);
    }

    /**
     * Карточка со всеми связями.
     */
    public function findOneWithRelations(int $id): ?KanbanCard
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.column', 'col')->addSelect('col')
            ->leftJoin('col.board', 'b')->addSelect('b')
            ->leftJoin('c.subtasks', 'ci')->addSelect('ci')
            ->leftJoin('c.comments', 'com')->addSelect('com')
            ->leftJoin('com.author', 'a')->addSelect('a')
            ->leftJoin('c.attachments', 'att')->addSelect('att')
            ->leftJoin('att.author', 'atta')->addSelect('atta')
            ->leftJoin('c.labels', 'lbl')->addSelect('lbl')
            ->leftJoin('c.assignees', 'asgn')->addSelect('asgn')
            ->where('c.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Карточки проекта, где указан пользователь в assignees.
     *
     * @return KanbanCard[]
     */
    public function findCardsInProjectWithAssignee(KanbanProject $project, User $user): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.column', 'col')->addSelect('col')
            ->innerJoin('col.board', 'b')
            ->innerJoin('c.assignees', 'a')
            ->where('b.project = :project')
            ->andWhere('a = :user')
            ->setParameter('project', $project)
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }

    /**
     * Перебалансировка позиций карточек в колонке.
     */
    public function rebalancePositions(KanbanColumn $column): void
    {
        $cards = $this->createQueryBuilder('c')
            ->where('c.column = :column')
            ->setParameter('column', $column)
            ->orderBy('c.position', 'ASC')
            ->getQuery()
            ->getResult();

        $pos = 1.0;
        foreach ($cards as $card) {
            $card->setPosition($pos);
            $pos += 1.0;
        }
    }
}
