<?php

namespace App\Repository\Kanban;

use App\Entity\Kanban\KanbanCard;
use App\Entity\Kanban\KanbanChecklistItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KanbanChecklistItem>
 */
class KanbanChecklistItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KanbanChecklistItem::class);
    }

    public function getMaxPosition(KanbanCard $card): float
    {
        $result = $this->createQueryBuilder('ci')
            ->select('MAX(ci.position)')
            ->where('ci.card = :card')
            ->setParameter('card', $card)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0.0);
    }
}
