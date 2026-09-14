<?php

declare(strict_types=1);

namespace App\Entity\Acknowledgment;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Файл документа в MinIO: содержимое в бакете, здесь только ключ объекта.
 *
 * Размер и mime не храним — их отдаёт сам MinIO вместе с телом при скачивании,
 * а лишние столбцы пришлось бы держать в согласии с бакетом.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ack_document_file')]
class AckDocumentFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AckDocument::class, inversedBy: 'files')]
    #[ORM\JoinColumn(name: 'document_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?AckDocument $document = null;

    #[ORM\Column(name: 'storage_key', length: 500)]
    private string $storageKey = '';

    #[ORM\Column(name: 'original_name', length: 255)]
    private string $originalName = '';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocument(): ?AckDocument
    {
        return $this->document;
    }

    public function setDocument(?AckDocument $document): static
    {
        $this->document = $document;

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

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function setOriginalName(string $originalName): static
    {
        $this->originalName = $originalName;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
