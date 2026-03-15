<?php

namespace App\Entity\User;

use App\Enum\User\UserFileType;
use App\Repository\User\UserFileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[ORM\Entity(repositoryClass: UserFileRepository::class)]
#[ORM\Table(name: 'file_user')]
#[Vich\Uploadable]
class UserFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'files')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(enumType: UserFileType::class, nullable: true)]
    private ?UserFileType $type = null;

    #[Vich\UploadableField(mapping: 'user_files', fileNameProperty: 'filePath')]
    private ?SymfonyFile $file = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $filePath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalName = null;

    public function __construct()
    {
        $this->type = UserFileType::OTHER;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getType(): ?UserFileType
    {
        return $this->type;
    }

    public function setType(?UserFileType $type): static
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Тип для отображения и группировки: если тип не присвоен — считаем «Разное».
     */
    public function getTypeForDisplay(): UserFileType
    {
        return $this->type ?? UserFileType::OTHER;
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

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function setOriginalName(?string $originalName): static
    {
        $this->originalName = $originalName;

        return $this;
    }
}
