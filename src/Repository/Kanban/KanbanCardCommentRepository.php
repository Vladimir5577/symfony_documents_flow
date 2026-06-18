<?php

namespace App\Repository\Kanban;

use App\Entity\Kanban\KanbanCard;
use App\Entity\Kanban\KanbanCardComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KanbanCardComment>
 */
class KanbanCardCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KanbanCardComment::class);
    }

    public function countByCard(KanbanCard $card): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.card = :card')
            ->setParameter('card', $card)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return KanbanCardComment[]
     */
    public function findByCard(KanbanCard $card): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.author', 'a')->addSelect('a')
            ->where('c.card = :card')
            ->setParameter('card', $card)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
