<?php

namespace App\Repository\Kanban;

use App\Entity\Kanban\KanbanCard;
use App\Entity\Kanban\KanbanCardActivity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KanbanCardActivity>
 */
class KanbanCardActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KanbanCardActivity::class);
    }

    /**
     * Лента истории одной карточки: свежие сверху, с пагинацией.
     * Запрашиваем на одну запись больше лимита, чтобы понять, есть ли «ещё».
     *
     * @return KanbanCardActivity[]
     */
    public function findByCard(KanbanCard $card, int $offset = 0, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.card = :card')
            ->setParameter('card', $card)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
