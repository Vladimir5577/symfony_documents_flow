<?php

namespace App\Service\Kanban;

use App\Entity\Kanban\KanbanBoard;
use App\Entity\Kanban\KanbanBoardMember;
use App\Entity\Kanban\KanbanCard;
use App\Entity\Kanban\KanbanColumn;
use App\Entity\User\User;
use App\Enum\KanbanBoardMemberRole;
use App\Repository\Kanban\KanbanBoardMemberRepository;
use App\Repository\Kanban\KanbanBoardRepository;
use App\Repository\Kanban\KanbanCardRepository;
use App\Repository\Kanban\KanbanColumnRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class KanbanService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KanbanBoardRepository $boardRepo,
        private readonly KanbanBoardMemberRepository $memberRepo,
        private readonly KanbanColumnRepository $columnRepo,
        private readonly KanbanCardRepository $cardRepo,
    ) {
    }

    /**
     * Получить роль пользователя на доске (null если не участник).
     */
    public function getMemberRole(KanbanBoard $board, User $user): ?KanbanBoardMemberRole
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return KanbanBoardMemberRole::ADMIN;
        }

        $member = $this->memberRepo->findByBoardAndUser($board, $user);
        return $member?->getRole();
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
     * Создать доску и назначить создателя администратором.
     */
    public function createBoard(string $title, User $creator): KanbanBoard
    {
        $board = new KanbanBoard();
        $board->setTitle($title);
        $board->setCreatedBy($creator);

        $member = new KanbanBoardMember();
        $member->setBoard($board);
        $member->setUser($creator);
        $member->setRole(KanbanBoardMemberRole::ADMIN);
        $board->addMember($member);

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
