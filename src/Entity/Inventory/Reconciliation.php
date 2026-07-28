<?php

namespace App\Entity\Inventory;

use App\Entity\User\User;
use App\Enum\Inventory\ReconciliationStatus;
use App\Repository\Inventory\ReconciliationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: ReconciliationRepository::class)]
#[ORM\Table(name: 'inventory_reconciliation')]
class Reconciliation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(name: 'file_path', length: 255)]
    private ?string $filePath = null;

    #[ORM\Column(name: 'as_of_date', type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $asOfDate = null;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Warehouse $warehouse = null;

    #[ORM\Column(name: 'max_movement_id', type: Types::BIGINT, nullable: true)]
    private ?int $maxMovementId = null;

    #[ORM\Column(length: 20, enumType: ReconciliationStatus::class, options: ['default' => 'draft'])]
    private ReconciliationStatus $status = ReconciliationStatus::DRAFT;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, ReconciliationLine> */
    #[ORM\OneToMany(mappedBy: 'reconciliation', targetEntity: ReconciliationLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): static
    {
        $this->filePath = $filePath;

        return $this;
    }

    public function getAsOfDate(): ?\DateTimeImmutable
    {
        return $this->asOfDate;
    }

    public function setAsOfDate(?\DateTimeImmutable $asOfDate): static
    {
        $this->asOfDate = $asOfDate;

        return $this;
    }

    public function getWarehouse(): ?Warehouse
    {
        return $this->warehouse;
    }

    public function setWarehouse(?Warehouse $warehouse): static
    {
        $this->warehouse = $warehouse;

        return $this;
    }

    public function getMaxMovementId(): ?int
    {
        return $this->maxMovementId;
    }

    public function setMaxMovementId(?int $maxMovementId): static
    {
        $this->maxMovementId = $maxMovementId;

        return $this;
    }

    public function getStatus(): ReconciliationStatus
    {
        return $this->status;
    }

    public function setStatus(ReconciliationStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
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

    /**
     * @return Collection<int, ReconciliationLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(ReconciliationLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setReconciliation($this);
        }

        return $this;
    }

    public function removeLine(ReconciliationLine $line): static
    {
        if ($this->lines->removeElement($line) && $line->getReconciliation() === $this) {
            $line->setReconciliation(null);
        }

        return $this;
    }
}
