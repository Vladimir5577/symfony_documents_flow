<?php

namespace App\Entity\Document;

use App\Entity\User\User;
use App\Repository\Document\DocumentCommentFileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[ORM\Entity(repositoryClass: DocumentCommentFileRepository::class)]
#[ORM\Table(name: 'document_comment_file')]
#[Vich\Uploadable]
class DocumentCommentFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Vich\UploadableField(
        mapping: 'document_comment_files',
        fileNameProperty: 'storageKey',
        size: 'sizeBytes',
        mimeType: 'contentType',
        originalName: 'filename',
    )]
    private ?SymfonyFile $file = null;

    #[ORM\Column(name: 'storage_key', length: 500, nullable: true)]
    private ?string $storageKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filename = null;

    #[ORM\Column(name: 'content_type', length: 100, nullable: true)]
    private ?string $contentType = null;

    #[ORM\Column(name: 'size_bytes', type: Types::INTEGER, nullable: true)]
    private ?int $sizeBytes = null;

    #[ORM\ManyToOne(targetEntity: DocumentComment::class, inversedBy: 'files')]
    #[ORM\JoinColumn(name: 'comment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private DocumentComment $comment;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFile(): ?SymfonyFile
    {
        return $this->file;
    }

    public function setFile(?SymfonyFile $file = null): void
    {
        $this->file = $file;

        if ($file !== null) {
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function getStorageKey(): ?string
    {
        return $this->storageKey;
    }

    public function setStorageKey(?string $storageKey): static
    {
        $this->storageKey = $storageKey;

        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(?string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    public function setContentType(?string $contentType): static
    {
        $this->contentType = $contentType;

        return $this;
    }

    public function getSizeBytes(): ?int
    {
        return $this->sizeBytes;
    }

    public function setSizeBytes(?int $sizeBytes): static
    {
        $this->sizeBytes = $sizeBytes;

        return $this;
    }

    public function getComment(): DocumentComment
    {
        return $this->comment;
    }

    public function setComment(DocumentComment $comment): static
    {
        $this->comment = $comment;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
