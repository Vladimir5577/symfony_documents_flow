<?php

namespace App\Entity\Kanban;

use App\Enum\KanbanCardPriority;
use App\Repository\Kanban\KanbanCardRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: KanbanCardRepository::class)]
#[ORM\Table(name: 'kanban_card')]
class KanbanCard
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 500)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, length: 50000, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'float')]
    private float $position = 0.0;

    #[ORM\Column(name: 'due_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column(type: 'smallint', enumType: KanbanCardPriority::class, nullable: true)]
    private ?KanbanCardPriority $priority = null;

    #[ORM\Column(name: 'is_archived', options: ['default' => false])]
    private bool $isArchived = false;

    #[ORM\ManyToOne(targetEntity: KanbanColumn::class, inversedBy: 'cards')]
    #[ORM\JoinColumn(name: 'column_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private KanbanColumn $column;

    /** @var Collection<int, KanbanChecklistItem> */
    #[ORM\OneToMany(mappedBy: 'card', targetEntity: KanbanChecklistItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $checklistItems;

    /** @var Collection<int, KanbanCardComment> */
    #[ORM\OneToMany(mappedBy: 'card', targetEntity: KanbanCardComment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $comments;

    /** @var Collection<int, KanbanAttachment> */
    #[ORM\OneToMany(mappedBy: 'card', targetEntity: KanbanAttachment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $attachments;

    /** @var Collection<int, KanbanLabel> */
    #[ORM\ManyToMany(targetEntity: KanbanLabel::class, inversedBy: 'cards')]
    #[ORM\JoinTable(name: 'kanban_card_label')]
    private Collection $labels;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->checklistItems = new ArrayCollection();
        $this->comments = new ArrayCollection();
        $this->attachments = new ArrayCollection();
        $this->labels = new ArrayCollection();
    }

    public function getId(): Uuid
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
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

    public function getDueAt(): ?\DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function setDueAt(?\DateTimeImmutable $dueAt): static
    {
        $this->dueAt = $dueAt;
        return $this;
    }

    public function getPriority(): ?KanbanCardPriority
    {
        return $this->priority;
    }

    public function setPriority(?KanbanCardPriority $priority): static
    {
        $this->priority = $priority;
        return $this;
    }

    public function isArchived(): bool
    {
        return $this->isArchived;
    }

    public function setIsArchived(bool $isArchived): static
    {
        $this->isArchived = $isArchived;
        return $this;
    }

    public function getColumn(): KanbanColumn
    {
        return $this->column;
    }

    public function setColumn(KanbanColumn $column): static
    {
        $this->column = $column;
        return $this;
    }

    /** @return Collection<int, KanbanChecklistItem> */
    public function getChecklistItems(): Collection
    {
        return $this->checklistItems;
    }

    public function addChecklistItem(KanbanChecklistItem $item): static
    {
        if (!$this->checklistItems->contains($item)) {
            $this->checklistItems->add($item);
            $item->setCard($this);
        }
        return $this;
    }

    /** @return Collection<int, KanbanCardComment> */
    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function addComment(KanbanCardComment $comment): static
    {
        if (!$this->comments->contains($comment)) {
            $this->comments->add($comment);
            $comment->setCard($this);
        }
        return $this;
    }

    /** @return Collection<int, KanbanAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(KanbanAttachment $attachment): static
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
            $attachment->setCard($this);
        }
        return $this;
    }

    /** @return Collection<int, KanbanLabel> */
    public function getLabels(): Collection
    {
        return $this->labels;
    }

    public function addLabel(KanbanLabel $label): static
    {
        if (!$this->labels->contains($label)) {
            $this->labels->add($label);
        }
        return $this;
    }

    public function removeLabel(KanbanLabel $label): static
    {
        $this->labels->removeElement($label);
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
