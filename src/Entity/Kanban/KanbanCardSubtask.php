<?php

namespace App\Entity\Kanban;

use App\Entity\User\User;
use App\Enum\Kanban\KanbanSubtaskStatus;
use App\Repository\Kanban\KanbanChecklistItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KanbanChecklistItemRepository::class)]
#[ORM\Table(name: 'kanban_card_subtask')]
class KanbanCardSubtask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 500)]
    private string $title;

    #[ORM\Column(name: 'status', type: 'string', enumType: KanbanSubtaskStatus::class, options: ['default' => KanbanSubtaskStatus::TO_DO])]
    private KanbanSubtaskStatus $status = KanbanSubtaskStatus::TO_DO;

    #[ORM\Column(type: 'float')]
    private float $position = 0.0;

    #[ORM\ManyToOne(targetEntity: KanbanCard::class, inversedBy: 'subtasks')]
    #[ORM\JoinColumn(name: 'card_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private KanbanCard $card;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getStatus(): KanbanSubtaskStatus
    {
        return $this->status;
    }

    public function setStatus(KanbanSubtaskStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function isCompleted(): bool
    {
        return $this->status === KanbanSubtaskStatus::DONE;
    }

    public function setIsCompleted(bool $isCompleted): static
    {
        $this->status = $isCompleted ? KanbanSubtaskStatus::DONE : KanbanSubtaskStatus::TO_DO;
        return $this;
    }

    public function getPosition(): float
    {
        return $this->position;
    }

    public function setPosition(float $position): static
    {
        $this->position = $position;
        return $this;
    }

    public function getCard(): KanbanCard
    {
        return $this->card;
    }

    public function setCard(KanbanCard $card): static
    {
        $this->card = $card;
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
}
