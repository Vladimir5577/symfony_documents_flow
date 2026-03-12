<?php

namespace App\Entity\Post;

use App\Repository\Post\FileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[ORM\Entity(repositoryClass: FileRepository::class)]
#[ORM\Table(name: 'file_post')]
#[Vich\Uploadable]
class File
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Post::class, inversedBy: 'files')]
    #[ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id', nullable: false)]
    private ?Post $post = null;

    #[Vich\UploadableField(mapping: 'post_files', fileNameProperty: 'filePath')]
    private ?SymfonyFile $file = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $filePath = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getPost(): ?Post
    {
        return $this->post;
    }

    public function setPost(?Post $post): static
    {
        $this->post = $post;

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

