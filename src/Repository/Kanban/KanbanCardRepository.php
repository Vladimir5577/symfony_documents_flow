<?php

namespace App\Repository\Kanban;

use App\Entity\Kanban\KanbanCard;
use App\Entity\Kanban\KanbanColumn;
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
            ->leftJoin('c.checklistItems', 'ci')->addSelect('ci')
            ->leftJoin('c.comments', 'com')->addSelect('com')
            ->leftJoin('com.author', 'a')->addSelect('a')
            ->leftJoin('c.attachments', 'att')->addSelect('att')
            ->leftJoin('c.labels', 'lbl')->addSelect('lbl')
            ->leftJoin('c.assignees', 'asgn')->addSelect('asgn')
            ->where('c.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
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
