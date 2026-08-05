<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Entity\User\User;
use App\Repository\Inventory\UpdFileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Файл документа: скан, pdf, docx или фотография.
 *
 * Файлов у одного УПД несколько — скан бывает постраничным, а к документу
 * прикладывают спецификацию. Само содержимое лежит в MinIO, здесь только ключ
 * объекта и то, что нужно для выдачи: имя, MIME-тип и размер.
 *
 * Внешний ключ на УПД — ON DELETE CASCADE, но удалять строки в обход сервиса
 * нельзя: объект останется висеть в бакете. Удаление идёт через сервис файлов,
 * он сносит сначала объект в MinIO, потом строку.
 */
#[ORM\Entity(repositoryClass: UpdFileRepository::class)]
#[ORM\Table(name: 'inventory_upd_file')]
#[ORM\Index(name: 'idx_inventory_upd_file_upd', columns: ['upd_id'])]
class UpdFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Upd::class)]
    #[ORM\JoinColumn(name: 'upd_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Upd $upd;

    /** Имя, под которым файл загрузили, — его же показываем и отдаём при скачивании. */
    #[ORM\Column(length: 255)]
    private string $filename;

    /** Ключ объекта в бакете MinIO. */
    #[ORM\Column(name: 'storage_key', length: 500)]
    private string $storageKey;

    #[ORM\Column(name: 'content_type', length: 100)]
    private string $contentType;

    #[ORM\Column(name: 'size_bytes', type: Types::INTEGER)]
    private int $sizeBytes;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'uploaded_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUpd(): Upd
    {
        return $this->upd;
    }

    public function setUpd(Upd $upd): static
    {
        $this->upd = $upd;

        return $this;
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

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
