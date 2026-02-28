<?php

namespace App\Entity\Kanban;

use App\Entity\Kanban\Project\KanbanProject;
use App\Entity\User\User;
use App\Repository\Kanban\KanbanBoardRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: KanbanBoardRepository::class)]
#[ORM\Table(name: 'kanban_board')]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt', timeAware: false, hardDelete: false)]
class KanbanBoard
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\ManyToOne(targetEntity: KanbanProject::class)]
    #[ORM\JoinColumn(name: 'kanban_project_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?KanbanProject $project = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    /** @var Collection<int, KanbanColumn> */
    #[ORM\OneToMany(mappedBy: 'board', targetEntity: KanbanColumn::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $columns;

    /** @var Collection<int, KanbanLabel> */
    #[ORM\OneToMany(mappedBy: 'board', targetEntity: KanbanLabel::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $labels;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct()
    {
        $this->columns = new ArrayCollection();
        $this->labels = new ArrayCollection();
    }

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

    public function getProject(): ?KanbanProject
    {
        return $this->project;
    }

    public function setProject(?KanbanProject $project): static
    {
        $this->project = $project;
        return $this;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    /** @return Collection<int, KanbanColumn> */
    public function getColumns(): Collection
    {
        return $this->columns;
    }

    public function addColumn(KanbanColumn $column): static
    {
        if (!$this->columns->contains($column)) {
            $this->columns->add($column);
            $column->setBoard($this);
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
            $label->setBoard($this);
        }
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

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): static
    {
        $this->deletedAt = $deletedAt;
        return $this;
    }
}
