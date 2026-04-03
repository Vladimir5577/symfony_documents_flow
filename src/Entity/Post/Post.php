<?php

namespace App\Entity\Post;

use App\Entity\Post\File as PostFile;
use App\Entity\User\User;
use App\Enum\Post\PostType;
use App\Repository\Post\PostRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[ORM\Entity(repositoryClass: PostRepository::class)]
#[ORM\Table(name: 'post')]
#[ORM\Index(
    name: 'idx_post_visible_created_at',
    columns: ['deleted_at', 'is_active', 'created_at']
)]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt', timeAware: false, hardDelete: false)]
#[Vich\Uploadable]
class Post
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(enumType: PostType::class)]
    private ?PostType $type = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $content = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $author = null;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'is_required_acknowledgment', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isRequiredAcknowledgment = false;

    #[Vich\UploadableField(mapping: 'post_cover', fileNameProperty: 'coverImageName')]
    private ?SymfonyFile $coverImageFile = null;

    #[ORM\Column(name: 'cover_image_name', type: Types::STRING, length: 255, nullable: true)]
    private ?string $coverImageName = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /**
     * @var Collection<int, PostFile>
     */
    #[ORM\OneToMany(mappedBy: 'post', targetEntity: PostFile::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $files;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->files = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getType(): ?PostType
    {
        return $this->type;
    }

    public function setType(?PostType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): self
    {
        $this->content = $content;

        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(User $author): self
    {
        $this->author = $author;

        return $this;
    }

    public function getCoverImageFile(): ?SymfonyFile
    {
        return $this->coverImageFile;
    }

    public function setCoverImageFile(?SymfonyFile $coverImageFile = null): self
    {
        $this->coverImageFile = $coverImageFile;

        return $this;
    }

    public function getCoverImageName(): ?string
    {
        return $this->coverImageName;
    }

    public function setCoverImageName(?string $coverImageName): self
    {
        $this->coverImageName = $coverImageName;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function isRequiredAcknowledgment(): bool
    {
        return $this->isRequiredAcknowledgment;
    }

    public function setIsRequiredAcknowledgment(bool $isRequiredAcknowledgment): self
    {
        $this->isRequiredAcknowledgment = $isRequiredAcknowledgment;

        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): self
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    /**
     * @return Collection<int, PostFile>
     */
    public function getFiles(): Collection
    {
        return $this->files;
    }

    public function addFile(PostFile $file): self
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
            $file->setPost($this);
        }

        return $this;
    }

    public function removeFile(PostFile $file): self
    {
        if ($this->files->removeElement($file)) {
            if ($file->getPost() === $this) {
                $file->setPost(null);
            }
        }

        return $this;
    }
}
