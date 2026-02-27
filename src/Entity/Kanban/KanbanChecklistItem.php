<?php

namespace App\Entity\Kanban;

use App\Repository\Kanban\KanbanChecklistItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KanbanChecklistItemRepository::class)]
#[ORM\Table(name: 'kanban_checklist_item')]
class KanbanChecklistItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 500)]
    private string $title;

    #[ORM\Column(name: 'is_completed', options: ['default' => false])]
    private bool $isCompleted = false;

    #[ORM\Column(type: 'float')]
    private float $position = 0.0;

    #[ORM\ManyToOne(targetEntity: KanbanCard::class, inversedBy: 'checklistItems')]
    #[ORM\JoinColumn(name: 'card_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private KanbanCard $card;

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

    public function isCompleted(): bool
    {
        return $this->isCompleted;
    }

    public function setIsCompleted(bool $isCompleted): static
    {
        $this->isCompleted = $isCompleted;
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
}
