<?php

namespace App\Entity\Inventory;

use App\Entity\User\User;
use App\Enum\Inventory\ImportRowStatus;
use App\Enum\Inventory\ImportRowType;
use App\Repository\Inventory\ImportRowRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ImportRowRepository::class)]
#[ORM\Table(name: 'inventory_import_row')]
#[ORM\Index(name: 'idx_inv_import_row_batch', columns: ['batch_id', 'status'])]
class ImportRow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ImportBatch::class, inversedBy: 'rows')]
    #[ORM\JoinColumn(name: 'batch_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ImportBatch $batch = null;

    #[ORM\Column(name: 'row_no')]
    private ?int $rowNo = null;

    #[ORM\Column(name: 'row_type', length: 20, enumType: ImportRowType::class)]
    private ?ImportRowType $rowType = null;

    /** @var array<string, mixed>|list<mixed> */
    #[ORM\Column(type: Types::JSONB)]
    private array $raw = [];

    #[ORM\Column(name: 'raw_name', length: 500, nullable: true)]
    private ?string $rawName = null;

    #[ORM\Column(name: 'raw_fio', length: 255, nullable: true)]
    private ?string $rawFio = null;

    #[ORM\Column(name: 'raw_subdivision', length: 500, nullable: true)]
    private ?string $rawSubdivision = null;

    #[ORM\ManyToOne(targetEntity: Nomenclature::class)]
    #[ORM\JoinColumn(name: 'matched_nomenclature_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Nomenclature $matchedNomenclature = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'matched_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $matchedUser = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $quantity = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $problem = null;

    #[ORM\Column(length: 20, enumType: ImportRowStatus::class, options: ['default' => 'unmatched'])]
    private ImportRowStatus $status = ImportRowStatus::UNMATCHED;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBatch(): ?ImportBatch
    {
        return $this->batch;
    }

    public function setBatch(?ImportBatch $batch): static
    {
        $this->batch = $batch;

        return $this;
    }

    public function getRowNo(): ?int
    {
        return $this->rowNo;
    }

    public function setRowNo(int $rowNo): static
    {
        $this->rowNo = $rowNo;

        return $this;
    }

    public function getRowType(): ?ImportRowType
    {
        return $this->rowType;
    }

    public function setRowType(ImportRowType $rowType): static
    {
        $this->rowType = $rowType;

        return $this;
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    public function getRaw(): array
    {
        return $this->raw;
    }

    /**
     * @param array<string, mixed>|list<mixed> $raw
     */
    public function setRaw(array $raw): static
    {
        $this->raw = $raw;

        return $this;
    }

    public function getRawName(): ?string
    {
        return $this->rawName;
    }

    public function setRawName(?string $rawName): static
    {
        $this->rawName = $rawName;

        return $this;
    }

    public function getRawFio(): ?string
    {
        return $this->rawFio;
    }

    public function setRawFio(?string $rawFio): static
    {
        $this->rawFio = $rawFio;

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

    public function getMatchedNomenclature(): ?Nomenclature
    {
        return $this->matchedNomenclature;
    }

    public function setMatchedNomenclature(?Nomenclature $matchedNomenclature): static
    {
        $this->matchedNomenclature = $matchedNomenclature;

        return $this;
    }

    public function getMatchedUser(): ?User
    {
        return $this->matchedUser;
    }

    public function setMatchedUser(?User $matchedUser): static
    {
        $this->matchedUser = $matchedUser;

        return $this;
    }

    public function getQuantity(): ?string
    {
        return $this->quantity;
    }

    public function setQuantity(?string $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getProblem(): ?string
    {
        return $this->problem;
    }

    public function setProblem(?string $problem): static
    {
        $this->problem = $problem;

        return $this;
    }

    public function getStatus(): ImportRowStatus
    {
        return $this->status;
    }

    public function setStatus(ImportRowStatus $status): static
    {
        $this->status = $status;

        return $this;
    }
}
