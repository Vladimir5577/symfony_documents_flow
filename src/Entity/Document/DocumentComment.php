<?php

namespace App\Entity\Document;

use App\Entity\User\User;
use App\Repository\Document\DocumentCommentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentCommentRepository::class)]
#[ORM\Table(name: 'document_comment')]
class DocumentComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, length: 10000)]
    private string $body = '';

    #[ORM\ManyToOne(targetEntity: Document::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(name: 'document_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $author;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, DocumentCommentFile> */
    #[ORM\OneToMany(mappedBy: 'comment', targetEntity: DocumentCommentFile::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
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

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function setDocument(Document $document): static
    {
        $this->document = $document;

        return $this;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function setAuthor(User $author): static
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

    /** @return Collection<int, DocumentCommentFile> */
    public function getFiles(): Collection
    {
        return $this->files;
    }

    public function addFile(DocumentCommentFile $file): static
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
            $file->setComment($this);
        }

        return $this;
    }

    public function removeFile(DocumentCommentFile $file): static
    {
        $this->files->removeElement($file);

        return $this;
    }
}
