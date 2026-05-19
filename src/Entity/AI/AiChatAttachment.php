<?php

declare(strict_types=1);

namespace App\Entity\AI;

use App\Repository\AI\AiChatAttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[ORM\Entity(repositoryClass: AiChatAttachmentRepository::class)]
#[ORM\Table(name: 'ai_chat_attachment')]
#[Vich\Uploadable]
class AiChatAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AiChatMessage::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'message_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?AiChatMessage $message = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[Vich\UploadableField(mapping: 'ai_files', fileNameProperty: 'filePath')]
    private ?SymfonyFile $file = null;

    #[ORM\Column(name: 'file_path', length: 255, nullable: true)]
    private ?string $filePath = null;

    #[ORM\Column(name: 'content_type', length: 100, nullable: true)]
    private ?string $contentType = null;

    #[ORM\Column(name: 'size_bytes', type: Types::INTEGER, nullable: true)]
    private ?int $sizeBytes = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessage(): ?AiChatMessage
    {
        return $this->message;
    }

    public function setMessage(?AiChatMessage $message): static
    {
        $this->message = $message;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;
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

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(?string $filePath): static
    {
        $this->filePath = $filePath;
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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isImage(): bool
    {
        return $this->contentType !== null && str_starts_with($this->contentType, 'image/');
    }
}
