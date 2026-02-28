<?php

namespace App\Entity\Kanban\Project;

use App\Entity\User\User;
use App\Enum\KanbanBoardMemberRole;
use App\Repository\Kanban\Project\KanbanProjectUserRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KanbanProjectUserRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_project_user', columns: ['kanban_project_id', 'user_id'])]
class KanbanProjectUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: KanbanProject::class)]
    #[ORM\JoinColumn(name: 'kanban_project_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?KanbanProject $kanbanProject = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?User $user = null;

    #[ORM\Column(enumType: KanbanBoardMemberRole::class)]
    private ?KanbanBoardMemberRole $role = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKanbanProject(): ?KanbanProject
    {
        return $this->kanbanProject;
    }

    public function setKanbanProject(?KanbanProject $kanbanProject): static
    {
        $this->kanbanProject = $kanbanProject;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getRole(): ?KanbanBoardMemberRole
    {
        return $this->role;
    }

    public function setRole(KanbanBoardMemberRole $role): static
    {
        $this->role = $role;

        return $this;
    }
}
