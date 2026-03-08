<?php

namespace App\Repository\Kanban;

use App\Entity\Kanban\KanbanCard;
use App\Entity\Kanban\KanbanCardSubtask;
use App\Entity\Kanban\Project\KanbanProject;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KanbanCardSubtask>
 */
class KanbanChecklistItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KanbanCardSubtask::class);
    }

    /**
     * Подзадачи проекта, где назначен пользователь.
     *
     * @return KanbanCardSubtask[]
     */
    public function findSubtasksInProjectWithUser(KanbanProject $project, User $user): array
    {
        return $this->createQueryBuilder('ci')
            ->innerJoin('ci.card', 'c')
            ->innerJoin('c.column', 'col')
            ->innerJoin('col.board', 'b')
            ->where('b.project = :project')
            ->andWhere('ci.user = :user')
            ->setParameter('project', $project)
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
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
