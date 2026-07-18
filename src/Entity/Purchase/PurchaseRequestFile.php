<?php

namespace App\Entity\Purchase;

use App\Entity\User\User;
use App\Repository\Purchase\PurchaseRequestFileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[ORM\Entity(repositoryClass: PurchaseRequestFileRepository::class)]
#[Vich\Uploadable]
class PurchaseRequestFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseRequest::class, inversedBy: 'files')]
    #[ORM\JoinColumn(name: 'purchase_request_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseRequest $purchaseRequest = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'uploaded_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    #[Vich\UploadableField(mapping: 'purchase_files', fileNameProperty: 'fileName')]
    private ?SymfonyFile $file = null;

    // Имя файла в хранилище (заполняет Vich)
    #[ORM\Column(name: 'file_name', length: 255, nullable: true)]
    private ?string $fileName = null;

    // Имя файла, каким его загрузил пользователь
    #[ORM\Column(name: 'original_name', length: 255)]
    private ?string $originalName = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPurchaseRequest(): ?PurchaseRequest
    {
        return $this->purchaseRequest;
    }

    public function setPurchaseRequest(?PurchaseRequest $purchaseRequest): static
    {
        $this->purchaseRequest = $purchaseRequest;

        return $this;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    public function getFile(): ?SymfonyFile
    {
        return $this->file;
    }

    public function setFile(?SymfonyFile $file = null): void
    {
        $this->file = $file;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileName(?string $fileName): static
    {
        $this->fileName = $fileName;

        return $this;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function setOriginalName(string $originalName): static
    {
        $this->originalName = $originalName;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
