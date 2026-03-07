<?php

namespace App\Repository\Kanban;

use App\Entity\Kanban\KanbanBoard;
use App\Entity\Kanban\KanbanColumn;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KanbanColumn>
 */
class KanbanColumnRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KanbanColumn::class);
    }

    public function getMaxPosition(KanbanBoard $board): float
    {
        $result = $this->createQueryBuilder('c')
            ->select('MAX(c.position)')
            ->where('c.board = :board')
            ->setParameter('board', $board)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0.0);
    }
}
