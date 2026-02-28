<?php

namespace App\Service\Kanban;

use App\Entity\Kanban\KanbanBoard;
use App\Entity\Kanban\KanbanCard;
use App\Entity\Kanban\KanbanColumn;
use App\Entity\Kanban\Project\KanbanProject;
use App\Entity\Kanban\Project\KanbanProjectUser;
use App\Entity\User\User;
use App\Enum\KanbanBoardMemberRole;
use App\Enum\KanbanColumnColor;
use App\Repository\Kanban\KanbanBoardRepository;
use App\Repository\Kanban\KanbanCardRepository;
use App\Repository\Kanban\KanbanColumnRepository;
use App\Repository\Kanban\Project\KanbanProjectUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class KanbanService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KanbanBoardRepository $boardRepo,
        private readonly KanbanProjectUserRepository $projectUserRepo,
        private readonly KanbanColumnRepository $columnRepo,
        private readonly KanbanCardRepository $cardRepo,
    ) {
    }

    /**
     * Получить роль пользователя на доске (null если не участник проекта).
     */
    public function getMemberRole(KanbanBoard $board, User $user): ?KanbanBoardMemberRole
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return KanbanBoardMemberRole::ADMIN;
        }

        $project = $board->getProject();
        if (!$project) {
            return null;
        }

        if ($project->getOwner() === $user) {
            return KanbanBoardMemberRole::ADMIN;
        }

        $projectUser = $this->projectUserRepo->findByProjectAndUser($project, $user);
        return $projectUser?->getRole();
    }

    /**
     * Проверить минимальную роль.
     */
    public function requireRole(KanbanBoard $board, User $user, KanbanBoardMemberRole $minRole): void
    {
        $role = $this->getMemberRole($board, $user);

        if ($role === null) {
            throw new AccessDeniedHttpException('Нет доступа к доске.');
        }

        $hierarchy = [
            KanbanBoardMemberRole::VIEWER->value => 1,
            KanbanBoardMemberRole::EDITOR->value => 2,
            KanbanBoardMemberRole::ADMIN->value => 3,
        ];

        if ($hierarchy[$role->value] < $hierarchy[$minRole->value]) {
            throw new AccessDeniedHttpException('Недостаточно прав.');
        }
    }

    /**
     * Цвета колонок по умолчанию (по индексу).
     */
    private const DEFAULT_COLUMN_COLORS = [
        KanbanColumnColor::BG_DARK,
        KanbanColumnColor::BG_PRIMARY,
        KanbanColumnColor::BG_WARNING,
        KanbanColumnColor::BG_SUCCESS,
    ];

    /**
     * Создать проект с владельцем и досками.
     * Возвращает первую созданную доску для редиректа.
     *
     * @param int[] $memberUserIds ID пользователей для добавления в проект (роль EDITOR)
     * @param array<int, array{title: string, columns: array<int, string>}> $boardsConfig Доски: каждая с title и columns (названия колонок)
     */
    public function createProject(string $name, ?string $description, User $creator, array $memberUserIds = [], array $boardsConfig = []): KanbanBoard
    {
        $project = new KanbanProject();
        $project->setName($name);
        $project->setDescription($description);
        $project->setOwner($creator);
        $project->setCreatedBy($creator);

        $projectUser = new KanbanProjectUser();
        $projectUser->setKanbanProject($project);
        $projectUser->setUser($creator);
        $projectUser->setRole(KanbanBoardMemberRole::ADMIN);

        $this->em->persist($project);
        $this->em->persist($projectUser);

        $creatorId = $creator->getId();
        $memberUserIds = array_unique(array_map('intval', $memberUserIds));
        foreach ($memberUserIds as $userId) {
            $userId = (int) $userId;
            if ($userId <= 0 || $userId === $creatorId) {
                continue;
            }
            $memberUser = $this->em->getRepository(User::class)->find($userId);
            if (!$memberUser instanceof User) {
                continue;
            }
            $member = new KanbanProjectUser();
            $member->setKanbanProject($project);
            $member->setUser($memberUser);
            $member->setRole(KanbanBoardMemberRole::EDITOR);
            $this->em->persist($member);
        }

        if ($boardsConfig === []) {
            $boardsConfig = [
                ['title' => 'Главная доска', 'columns' => ['Backlog', 'To Do', 'In Progress', 'Done']],
            ];
        }

        $firstBoard = null;
        foreach ($boardsConfig as $boardData) {
            $title = trim((string) ($boardData['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $columns = isset($boardData['columns']) && is_array($boardData['columns'])
                ? array_values(array_filter(array_map('trim', $boardData['columns'])))
                : [];
            if ($columns === []) {
                $columns = ['Backlog', 'To Do', 'In Progress', 'Done'];
            }
            $board = $this->createBoard($project, $title, $creator);
            if ($firstBoard === null) {
                $firstBoard = $board;
            }
            foreach ($columns as $i => $columnTitle) {
                $color = self::DEFAULT_COLUMN_COLORS[$i % count(self::DEFAULT_COLUMN_COLORS)] ?? KanbanColumnColor::BG_INFO;
                $this->createColumn($board, $columnTitle, $color);
            }
        }

        if ($firstBoard === null) {
            $board = $this->createBoard($project, 'Главная доска', $creator);
            $this->createColumn($board, 'Backlog', KanbanColumnColor::BG_DARK);
            $this->createColumn($board, 'To Do', KanbanColumnColor::BG_PRIMARY);
            $this->createColumn($board, 'In Progress', KanbanColumnColor::BG_WARNING);
            $this->createColumn($board, 'Done', KanbanColumnColor::BG_SUCCESS);
            $firstBoard = $board;
        }

        return $firstBoard;
    }

    /**
     * Создать доску в проекте.
     */
    public function createBoard(KanbanProject $project, string $title, User $creator): KanbanBoard
    {
        $board = new KanbanBoard();
        $board->setProject($project);
        $board->setTitle($title);
        $board->setCreatedBy($creator);

        $this->em->persist($board);
        $this->em->flush();

        return $board;
    }

    /**
     * Создать колонку (позиция = max + 1).
     */
    public function createColumn(KanbanBoard $board, string $title, ?\App\Enum\KanbanColumnColor $color = null): KanbanColumn
    {
        $maxPos = $this->columnRepo->getMaxPosition($board);

        $column = new KanbanColumn();
        $column->setTitle($title);
        $column->setPosition($maxPos + 1.0);
        $column->setBoard($board);
        if ($color) {
            $column->setHeaderColor($color);
        }

        $this->em->persist($column);
        $this->em->flush();

        return $column;
    }

    /**
     * Создать карточку (позиция = max + 1).
     */
    public function createCard(KanbanColumn $column, string $title): KanbanCard
    {
        $maxPos = $this->cardRepo->getMaxPosition($column);

        $card = new KanbanCard();
        $card->setTitle($title);
        $card->setPosition($maxPos + 1.0);
        $card->setColumn($column);

        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }

    /**
     * Переместить карточку в другую колонку/позицию с оптимистичной блокировкой.
     */
    public function moveCard(KanbanCard $card, KanbanColumn $targetColumn, float $position, ?\DateTimeImmutable $prevUpdatedAt = null): void
    {
        if ($prevUpdatedAt !== null && $card->getUpdatedAt() !== null) {
            $diff = abs($card->getUpdatedAt()->getTimestamp() - $prevUpdatedAt->getTimestamp());
            if ($diff > 1) {
                throw new ConflictHttpException('Карточка была изменена другим пользователем.');
            }
        }

        $card->setColumn($targetColumn);
        $card->setPosition($position);

        $this->em->flush();

        // Перебалансировка при слишком малых дельтах
        $this->rebalanceIfNeeded($targetColumn);
    }

    /**
     * Удалить колонку (409 если есть карточки).
     */
    public function deleteColumn(KanbanColumn $column): void
    {
        if ($column->getCards()->count() > 0) {
            throw new ConflictHttpException('Нельзя удалить колонку с карточками. Переместите или удалите карточки.');
        }

        $this->em->remove($column);
        $this->em->flush();
    }

    private function rebalanceIfNeeded(KanbanColumn $column): void
    {
        $cards = $this->cardRepo->findBy(['column' => $column], ['position' => 'ASC']);
        if (count($cards) < 2) {
            return;
        }

        $needsRebalance = false;
        for ($i = 1; $i < count($cards); $i++) {
            if (abs($cards[$i]->getPosition() - $cards[$i - 1]->getPosition()) < 1e-5) {
                $needsRebalance = true;
                break;
            }
        }

        if ($needsRebalance) {
            $this->cardRepo->rebalancePositions($column);
            $this->em->flush();
        }
    }
}
