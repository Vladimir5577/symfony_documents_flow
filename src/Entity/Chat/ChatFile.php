<?php

namespace App\Entity\Chat;

use App\Repository\Chat\ChatFileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[ORM\Entity(repositoryClass: ChatFileRepository::class)]
#[ORM\Table(name: 'file_chat')]
#[Vich\Uploadable]
class ChatFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ChatMessage::class, inversedBy: 'files')]
    #[ORM\JoinColumn(name: 'message_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ChatMessage $message = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[Vich\UploadableField(mapping: 'chat_files', fileNameProperty: 'filePath')]
    private ?SymfonyFile $file = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $filePath = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessage(): ?ChatMessage
    {
        return $this->message;
    }

    public function setMessage(?ChatMessage $message): static
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
}
