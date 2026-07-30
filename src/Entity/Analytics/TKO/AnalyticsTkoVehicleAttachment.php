<?php

declare(strict_types=1);

namespace App\Entity\Analytics\TKO;

use App\Entity\User\User;
use App\Repository\Analytics\TKO\AnalyticsTkoVehicleAttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Вложение ТС: документы ТС (vehicle) или внутренние (internal).
 */
#[ORM\Entity(repositoryClass: AnalyticsTkoVehicleAttachmentRepository::class)]
#[ORM\Table(name: 'analytics_tko_vehicle_attachment')]
#[ORM\Index(name: 'idx_analytics_tko_vehicle_attachment_vehicle_id', columns: ['vehicle_id'])]
#[ORM\Index(name: 'idx_analytics_tko_vehicle_attachment_context', columns: ['context'])]
class AnalyticsTkoVehicleAttachment
{
    public const CONTEXT_VEHICLE = 'vehicle';
    public const CONTEXT_INTERNAL = 'internal';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $filename = '';

    #[ORM\Column(name: 'storage_key', length: 500)]
    private string $storageKey = '';

    #[ORM\Column(name: 'content_type', length: 100)]
    private string $contentType = '';

    #[ORM\Column(name: 'size_bytes', type: Types::INTEGER)]
    private int $sizeBytes = 0;

    #[ORM\Column(length: 20, options: ['default' => self::CONTEXT_VEHICLE])]
    private string $context = self::CONTEXT_VEHICLE;

    #[ORM\ManyToOne(targetEntity: AnalyticsTkoVehicle::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'vehicle_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?AnalyticsTkoVehicle $vehicle = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function setStorageKey(string $storageKey): static
    {
        $this->storageKey = $storageKey;

        return $this;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function setContentType(string $contentType): static
    {
        $this->contentType = $contentType;

        return $this;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function setSizeBytes(int $sizeBytes): static
    {
        $this->sizeBytes = $sizeBytes;

        return $this;
    }

    public function getContext(): string
    {
        return $this->context;
    }

    public function setContext(string $context): static
    {
        $this->context = $context;

        return $this;
    }

    public function getVehicle(): ?AnalyticsTkoVehicle
    {
        return $this->vehicle;
    }

    public function setVehicle(?AnalyticsTkoVehicle $vehicle): static
    {
        $this->vehicle = $vehicle;

        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
