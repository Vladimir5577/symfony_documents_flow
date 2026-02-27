<?php

namespace App\Entity\Kanban;

use App\Entity\User\User;
use App\Enum\KanbanBoardMemberRole;
use App\Repository\Kanban\KanbanBoardMemberRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KanbanBoardMemberRepository::class)]
#[ORM\Table(name: 'kanban_board_member')]
#[ORM\UniqueConstraint(name: 'uniq_board_member', columns: ['board_id', 'user_id'])]
class KanbanBoardMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: KanbanBoard::class, inversedBy: 'members')]
    #[ORM\JoinColumn(name: 'board_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private KanbanBoard $board;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20, enumType: KanbanBoardMemberRole::class)]
    private KanbanBoardMemberRole $role;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBoard(): KanbanBoard
    {
        return $this->board;
    }

    public function setBoard(KanbanBoard $board): static
    {
        $this->board = $board;
        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getRole(): KanbanBoardMemberRole
    {
        return $this->role;
    }

    public function setRole(KanbanBoardMemberRole $role): static
    {
        $this->role = $role;
        return $this;
    }
}
