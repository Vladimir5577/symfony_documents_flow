<?php

namespace App\Repository\Kanban;

use App\Entity\Kanban\KanbanCard;
use App\Entity\Kanban\KanbanBoard;
use App\Entity\Kanban\Project\KanbanProject;
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
     * Доски проекта (для табов переключения).
     *
     * @return KanbanBoard[]
     */
    public function findByProject(KanbanProject $project): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.project = :project')
            ->setParameter('project', $project)
            ->orderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Доски проекта, в которых у пользователя есть назначенные карточки.
     *
     * @return KanbanBoard[]
     */
    public function findByProjectAndUserWithAssignedCards(KanbanProject $project, User $user): array
    {
        return $this->createQueryBuilder('b')
            ->distinct()
            ->innerJoin('b.columns', 'col')
            ->innerJoin('col.cards', 'card')
            ->innerJoin('card.assignees', 'assignee')
            ->where('b.project = :project')
            ->andWhere('assignee = :user')
            ->setParameter('project', $project)
            ->setParameter('user', $user)
            ->orderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Все доски, на которых у пользователя есть назначенные карточки.
     * Упорядочено по project.id, board.id для группировки по проекту.
     *
     * @return KanbanBoard[]
     */
    public function findAllByUserWithAssignedCards(User $user): array
    {
        return $this->createQueryBuilder('b')
            ->innerJoin('b.project', 'p')->addSelect('p')
            ->innerJoin('b.columns', 'col')
            ->innerJoin('col.cards', 'card')
            ->innerJoin('card.assignees', 'assignee')
            ->where('assignee = :user')
            ->setParameter('user', $user)
            ->orderBy('p.id', 'ASC')
            ->addOrderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Первая доска каждого из проектов (по id).
     *
     * @param int[] $projectIds
     * @return array<int, KanbanBoard> projectId => first board
     */
    public function findFirstBoardByProjectIds(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $boards = $this->createQueryBuilder('b')
            ->innerJoin('b.project', 'p')->addSelect('p')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $projectIds)
            ->orderBy('p.id', 'ASC')
            ->addOrderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($boards as $board) {
            $pid = $board->getProject()?->getId();
            if ($pid !== null && !isset($result[$pid])) {
                $result[$pid] = $board;
            }
        }
        return $result;
    }

    /**
     * Есть ли у пользователя назначенные карточки на данной доске.
     */
    public function userHasAssignedCardsOnBoard(KanbanBoard $board, User $user): bool
    {
        $result = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(KanbanCard::class, 'card')
            ->innerJoin('card.column', 'col')
            ->innerJoin('card.assignees', 'a')
            ->where('col.board = :board')
            ->andWhere('a = :user')
            ->setParameter('board', $board)
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result !== null;
    }

    /**
     * Все доски проекта с eager-загрузкой колонок.
     *
     * @return KanbanBoard[]
     */
    public function findByProjectWithColumns(KanbanProject $project): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.columns', 'col')->addSelect('col')
            ->where('b.project = :project')
            ->setParameter('project', $project)
            ->orderBy('b.id', 'ASC')
            ->addOrderBy('col.id', 'ASC')
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
            ->leftJoin('card.assignees', 'cardAsgn')->addSelect('cardAsgn')
            ->leftJoin('card.subtasks', 'ci')->addSelect('ci')
            ->where('b.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
