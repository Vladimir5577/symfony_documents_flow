<?php

namespace App\Entity;

use App\Enum\DocumentStatus;
use App\Repository\DocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt', timeAware: false, hardDelete: false)]
class Document
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_creator_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Организация-создатель обязательна для заполнения.')]
    private Organization $organizationCreator;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Название документа обязательно для заполнения.')]
    #[Assert\Length(
        min: 1,
        max: 255,
        minMessage: 'Название документа не может быть пустым.',
        maxMessage: 'Название документа не должно превышать {{ limit }} символов.'
    )]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    // document_type (ManyToOne relation)
    #[ORM\ManyToOne(targetEntity: DocumentType::class)]
    #[ORM\JoinColumn(name: 'document_type_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Тип документа обязателен для заполнения.')]
    private ?DocumentType $documentType = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true, enumType: DocumentStatus::class)]
    private ?DocumentStatus $status = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $deadline = null;

    #[ORM\Column(name: 'original_file', length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'Путь к оригинальному файлу не должен превышать {{ limit }} символов.')]
    private ?string $originalFile = null;

    #[ORM\Column(name: 'updated_file', length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'Путь к обновлённому файлу не должен превышать {{ limit }} символов.')]
    private ?string $updatedFile = null;

    // created_at
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    // updated_at
    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    // deleted_at (soft delete)
    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    // created_by (author)
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /** @var Collection<int, DocumentUserRecipient> */
    #[ORM\OneToMany(mappedBy: 'document', targetEntity: DocumentUserRecipient::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $userRecipients;

    public function __construct()
    {
        $this->userRecipients = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

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

    public function getDocumentType(): ?DocumentType
    {
        return $this->documentType;
    }

    public function setDocumentType(?DocumentType $documentType): static
    {
        $this->documentType = $documentType;

        return $this;
    }

    public function getStatus(): ?DocumentStatus
    {
        return $this->status;
    }

    public function setStatus(?DocumentStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getDeadline(): ?\DateTime
    {
        return $this->deadline;
    }

    public function setDeadline(?\DateTime $deadline): static
    {
        $this->deadline = $deadline;

        return $this;
    }

    public function getOriginalFile(): ?string
    {
        return $this->originalFile;
    }

    public function setOriginalFile(?string $originalFile): static
    {
        $this->originalFile = $originalFile;

        return $this;
    }

    public function getUpdatedFile(): ?string
    {
        return $this->updatedFile;
    }

    public function setUpdatedFile(?string $updatedFile): static
    {
        $this->updatedFile = $updatedFile;

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

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getOrganizationCreator(): Organization
    {
        return $this->organizationCreator;
    }

    public function setOrganizationCreator(Organization $organizationCreator): static
    {
        $this->organizationCreator = $organizationCreator;

        return $this;
    }

    /**
     * @return Collection<int, DocumentUserRecipient>
     */
    public function getUserRecipients(): Collection
    {
        return $this->userRecipients;
    }

    public function addUserRecipient(DocumentUserRecipient $userRecipient): static
    {
        if (!$this->userRecipients->contains($userRecipient)) {
            $this->userRecipients->add($userRecipient);
            $userRecipient->setDocument($this);
        }

        return $this;
    }

    public function removeUserRecipient(DocumentUserRecipient $userRecipient): static
    {
        if ($this->userRecipients->removeElement($userRecipient)) {
            // set the owning side to null (unless already changed)
            if ($userRecipient->getDocument() === $this) {
                $userRecipient->setDocument(null);
            }
        }

        return $this;
    }
}
