<?php

namespace App\Entity\Inventory;

use App\Entity\User\User;
use App\Enum\Inventory\DocumentStatus;
use App\Enum\Inventory\DocumentType;
use App\Repository\Inventory\DocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Table(name: 'inventory_document')]
#[ORM\UniqueConstraint(name: 'uniq_inv_document_number', columns: ['number'])]
#[ORM\Index(name: 'idx_inv_document_type_status', columns: ['type', 'status'])]
#[ORM\Index(name: 'idx_inv_document_doc_date', columns: ['doc_date'])]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt', timeAware: false, hardDelete: false)]
class Document
{
    /**
     * Префикс временного номера черновика.
     *
     * Колонка `number` — NOT NULL UNIQUE, поэтому черновик не может жить без номера,
     * а настоящий номер выдаётся счётчиком только при проведении. По этому префиксу
     * StockPostingService понимает, что номер временный и его нужно заменить.
     * Строка несущая: менять её без правки проверки в StockPostingService нельзя.
     */
    public const DRAFT_NUMBER_PREFIX = 'ЧЕРН-';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, enumType: DocumentType::class)]
    private ?DocumentType $type = null;

    #[ORM\Column(length: 50)]
    private ?string $number = null;

    #[ORM\Column(name: 'doc_date', type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $docDate = null;

    #[ORM\Column(length: 20, enumType: DocumentStatus::class, options: ['default' => 'draft'])]
    private DocumentStatus $status = DocumentStatus::DRAFT;

    #[ORM\Column(name: 'external_number', length: 100, nullable: true)]
    private ?string $externalNumber = null;

    #[ORM\Column(name: 'external_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $externalDate = null;

    #[ORM\Column(name: 'supplier_name', length: 255, nullable: true)]
    private ?string $supplierName = null;

    #[ORM\Column(name: 'supplier_inn', length: 20, nullable: true)]
    private ?string $supplierInn = null;

    #[ORM\ManyToOne(targetEntity: BasisType::class)]
    #[ORM\JoinColumn(name: 'basis_type_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?BasisType $basisType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\OneToOne(targetEntity: self::class, inversedBy: 'reversalDocument')]
    #[ORM\JoinColumn(name: 'reverses_document_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', unique: true)]
    private ?self $reversesDocument = null;

    #[ORM\OneToOne(mappedBy: 'reversesDocument', targetEntity: self::class)]
    private ?self $reversalDocument = null;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'default_managing_warehouse_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Warehouse $defaultManagingWarehouse = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'posted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $postedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'posted_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $postedBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /** @var Collection<int, DocumentLine> */
    #[ORM\OneToMany(mappedBy: 'document', targetEntity: DocumentLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lines;

    /** @var Collection<int, DocumentFile> */
    #[ORM\OneToMany(mappedBy: 'document', targetEntity: DocumentFile::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $files;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
        $this->files = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ?DocumentType
    {
        return $this->type;
    }

    public function setType(DocumentType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function setNumber(string $number): static
    {
        $this->number = $number;

        return $this;
    }

    public function getDocDate(): ?\DateTimeImmutable
    {
        return $this->docDate;
    }

    public function setDocDate(?\DateTimeImmutable $docDate): static
    {
        $this->docDate = $docDate;

        return $this;
    }

    public function getStatus(): DocumentStatus
    {
        return $this->status;
    }

    public function setStatus(DocumentStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getExternalNumber(): ?string
    {
        return $this->externalNumber;
    }

    public function setExternalNumber(?string $externalNumber): static
    {
        $this->externalNumber = $externalNumber;

        return $this;
    }

    public function getExternalDate(): ?\DateTimeImmutable
    {
        return $this->externalDate;
    }

    public function setExternalDate(?\DateTimeImmutable $externalDate): static
    {
        $this->externalDate = $externalDate;

        return $this;
    }

    public function getSupplierName(): ?string
    {
        return $this->supplierName;
    }

    public function setSupplierName(?string $supplierName): static
    {
        $this->supplierName = $supplierName;

        return $this;
    }

    public function getSupplierInn(): ?string
    {
        return $this->supplierInn;
    }

    public function setSupplierInn(?string $supplierInn): static
    {
        $this->supplierInn = $supplierInn;

        return $this;
    }

    public function getBasisType(): ?BasisType
    {
        return $this->basisType;
    }

    public function setBasisType(?BasisType $basisType): static
    {
        $this->basisType = $basisType;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getReversesDocument(): ?self
    {
        return $this->reversesDocument;
    }

    public function setReversesDocument(?self $reversesDocument): static
    {
        $this->reversesDocument = $reversesDocument;

        return $this;
    }

    public function getReversalDocument(): ?self
    {
        return $this->reversalDocument;
    }

    public function setReversalDocument(?self $reversalDocument): static
    {
        $this->reversalDocument = $reversalDocument;

        return $this;
    }

    public function getDefaultManagingWarehouse(): ?Warehouse
    {
        return $this->defaultManagingWarehouse;
    }

    public function setDefaultManagingWarehouse(?Warehouse $defaultManagingWarehouse): static
    {
        $this->defaultManagingWarehouse = $defaultManagingWarehouse;

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

    public function getPostedAt(): ?\DateTimeImmutable
    {
        return $this->postedAt;
    }

    public function setPostedAt(?\DateTimeImmutable $postedAt): static
    {
        $this->postedAt = $postedAt;

        return $this;
    }

    public function getPostedBy(): ?User
    {
        return $this->postedBy;
    }

    public function setPostedBy(?User $postedBy): static
    {
        $this->postedBy = $postedBy;

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

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): static
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    /**
     * @return Collection<int, DocumentLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(DocumentLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setDocument($this);
        }

        return $this;
    }

    public function removeLine(DocumentLine $line): static
    {
        if ($this->lines->removeElement($line) && $line->getDocument() === $this) {
            $line->setDocument(null);
        }

        return $this;
    }

    /**
     * @return Collection<int, DocumentFile>
     */
    public function getFiles(): Collection
    {
        return $this->files;
    }

    public function addFile(DocumentFile $file): static
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
            $file->setDocument($this);
        }

        return $this;
    }

    public function removeFile(DocumentFile $file): static
    {
        if ($this->files->removeElement($file) && $file->getDocument() === $this) {
            $file->setDocument(null);
        }

        return $this;
    }
}
