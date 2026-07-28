<?php

namespace App\Entity\Inventory;

use App\Enum\Inventory\ComparisonStatus;
use App\Enum\Inventory\ResolutionStatus;
use App\Repository\Inventory\ReconciliationLineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: ReconciliationLineRepository::class)]
#[ORM\Table(name: 'inventory_reconciliation_line')]
#[ORM\Index(name: 'idx_inv_recon_line_recon', columns: ['reconciliation_id', 'comparison_status'])]
class ReconciliationLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reconciliation::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'reconciliation_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Reconciliation $reconciliation = null;

    #[ORM\Column(name: 'raw_name', length: 500)]
    private ?string $rawName = null;

    #[ORM\Column(name: 'raw_subdivision', length: 500, nullable: true)]
    private ?string $rawSubdivision = null;

    #[ORM\ManyToOne(targetEntity: Nomenclature::class)]
    #[ORM\JoinColumn(name: 'nomenclature_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Nomenclature $nomenclature = null;

    #[ORM\Column(name: 'qty_1c', type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $qty1c = null;

    #[ORM\Column(name: 'qty_system', type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $qtySystem = null;

    #[ORM\Column(name: 'comparison_status', length: 30, enumType: ComparisonStatus::class)]
    private ?ComparisonStatus $comparisonStatus = null;

    #[ORM\Column(
        name: 'resolution_status',
        length: 30,
        enumType: ResolutionStatus::class,
        options: ['default' => 'none'],
    )]
    private ResolutionStatus $resolutionStatus = ResolutionStatus::NONE;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReconciliation(): ?Reconciliation
    {
        return $this->reconciliation;
    }

    public function setReconciliation(?Reconciliation $reconciliation): static
    {
        $this->reconciliation = $reconciliation;

        return $this;
    }

    public function getRawName(): ?string
    {
        return $this->rawName;
    }

    public function setRawName(string $rawName): static
    {
        $this->rawName = $rawName;

        return $this;
    }

    public function getRawSubdivision(): ?string
    {
        return $this->rawSubdivision;
    }

    public function setRawSubdivision(?string $rawSubdivision): static
    {
        $this->rawSubdivision = $rawSubdivision;

        return $this;
    }

    public function getNomenclature(): ?Nomenclature
    {
        return $this->nomenclature;
    }

    public function setNomenclature(?Nomenclature $nomenclature): static
    {
        $this->nomenclature = $nomenclature;

        return $this;
    }

    public function getQty1c(): ?string
    {
        return $this->qty1c;
    }

    public function setQty1c(?string $qty1c): static
    {
        $this->qty1c = $qty1c;

        return $this;
    }

    public function getQtySystem(): ?string
    {
        return $this->qtySystem;
    }

    public function setQtySystem(?string $qtySystem): static
    {
        $this->qtySystem = $qtySystem;

        return $this;
    }

    public function getComparisonStatus(): ?ComparisonStatus
    {
        return $this->comparisonStatus;
    }

    public function setComparisonStatus(ComparisonStatus $comparisonStatus): static
    {
        $this->comparisonStatus = $comparisonStatus;

        return $this;
    }

    public function getResolutionStatus(): ResolutionStatus
    {
        return $this->resolutionStatus;
    }

    public function setResolutionStatus(ResolutionStatus $resolutionStatus): static
    {
        $this->resolutionStatus = $resolutionStatus;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

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
}
