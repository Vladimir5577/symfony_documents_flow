<?php

namespace App\Entity\Inventory;

use App\Entity\User\User;
use App\Enum\Inventory\ImportBatchStatus;
use App\Enum\Inventory\ImportFormat;
use App\Repository\Inventory\ImportBatchRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: ImportBatchRepository::class)]
#[ORM\Table(name: 'inventory_import_batch')]
#[ORM\UniqueConstraint(name: 'uniq_inv_import_batch_file_hash', columns: ['file_hash'])]
class ImportBatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: ImportFormat::class)]
    private ?ImportFormat $format = null;

    #[ORM\Column(length: 255)]
    private ?string $filename = null;

    #[ORM\Column(name: 'file_hash', length: 64, options: ['fixed' => true])]
    private ?string $fileHash = null;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'target_warehouse_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Warehouse $targetWarehouse = null;

    #[ORM\Column(length: 20, enumType: ImportBatchStatus::class, options: ['default' => 'parsed'])]
    private ImportBatchStatus $status = ImportBatchStatus::PARSED;

    #[ORM\Column(name: 'totals_expected', options: ['default' => 0])]
    private int $totalsExpected = 0;

    #[ORM\Column(name: 'totals_recognized', options: ['default' => 0])]
    private int $totalsRecognized = 0;

    #[ORM\Column(name: 'totals_rejected', options: ['default' => 0])]
    private int $totalsRejected = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    /** @var Collection<int, ImportRow> */
    #[ORM\OneToMany(mappedBy: 'batch', targetEntity: ImportRow::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $rows;

    public function __construct()
    {
        $this->rows = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFormat(): ?ImportFormat
    {
        return $this->format;
    }

    public function setFormat(ImportFormat $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    public function getFileHash(): ?string
    {
        return $this->fileHash;
    }

    public function setFileHash(string $fileHash): static
    {
        $this->fileHash = $fileHash;

        return $this;
    }

    public function getTargetWarehouse(): ?Warehouse
    {
        return $this->targetWarehouse;
    }

    public function setTargetWarehouse(?Warehouse $targetWarehouse): static
    {
        $this->targetWarehouse = $targetWarehouse;

        return $this;
    }

    public function getStatus(): ImportBatchStatus
    {
        return $this->status;
    }

    public function setStatus(ImportBatchStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getTotalsExpected(): int
    {
        return $this->totalsExpected;
    }

    public function setTotalsExpected(int $totalsExpected): static
    {
        $this->totalsExpected = $totalsExpected;

        return $this;
    }

    public function getTotalsRecognized(): int
    {
        return $this->totalsRecognized;
    }

    public function setTotalsRecognized(int $totalsRecognized): static
    {
        $this->totalsRecognized = $totalsRecognized;

        return $this;
    }

    public function getTotalsRejected(): int
    {
        return $this->totalsRejected;
    }

    public function setTotalsRejected(int $totalsRejected): static
    {
        $this->totalsRejected = $totalsRejected;

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

    /**
     * @return Collection<int, ImportRow>
     */
    public function getRows(): Collection
    {
        return $this->rows;
    }

    public function addRow(ImportRow $row): static
    {
        if (!$this->rows->contains($row)) {
            $this->rows->add($row);
            $row->setBatch($this);
        }

        return $this;
    }

    public function removeRow(ImportRow $row): static
    {
        if ($this->rows->removeElement($row) && $row->getBatch() === $this) {
            $row->setBatch(null);
        }

        return $this;
    }
}
