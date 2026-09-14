<?php

declare(strict_types=1);

namespace App\Entity\Acknowledgment;

use App\Entity\User\User;
use App\Enum\Acknowledgment\AckAudience;
use App\Repository\Acknowledgment\AckDocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Документ, который сотрудники должны прочитать и отметить ознакомление.
 *
 * Публикация — это publishedAt: пока null, документ виден только автору и
 * делопроизводству, и обязанности ни у кого нет. Отдельного флага isPublished
 * нет намеренно: дата отвечает и «опубликован ли», и «с какого момента», а два
 * поля про одно состояние рано или поздно разъезжаются.
 *
 * archivedAt — «утратил силу»: документ уходит из списков сотрудников, но
 * остаётся в реестре и в отчётах.
 */
#[ORM\Entity(repositoryClass: AckDocumentRepository::class)]
#[ORM\Table(name: 'ack_document')]
#[ORM\Index(name: 'idx_ack_document_visible', columns: ['deleted_at', 'archived_at', 'published_at'])]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt', timeAware: false, hardDelete: false)]
class AckDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AckCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Категория обязательна для заполнения.')]
    private ?AckCategory $category = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Название документа обязательно для заполнения.')]
    #[Assert\Length(max: 255, maxMessage: 'Название документа не должно превышать {{ limit }} символов.')]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: AckAudience::class, options: ['default' => 'ALL'])]
    private AckAudience $audience = AckAudience::ALL;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deadline = null;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'archived_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /** @var Collection<int, AckDocumentFile> */
    #[ORM\OneToMany(mappedBy: 'document', targetEntity: AckDocumentFile::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $files;

    public function __construct()
    {
        $this->files = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): ?AckCategory
    {
        return $this->category;
    }

    public function setCategory(?AckCategory $category): static
    {
        $this->category = $category;

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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
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

    public function getAudience(): AckAudience
    {
        return $this->audience;
    }

    public function setAudience(AckAudience $audience): static
    {
        $this->audience = $audience;

        return $this;
    }

    public function getDeadline(): ?\DateTimeImmutable
    {
        return $this->deadline;
    }

    public function setDeadline(?\DateTimeImmutable $deadline): static
    {
        $this->deadline = $deadline;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->publishedAt !== null;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function setArchivedAt(?\DateTimeImmutable $archivedAt): static
    {
        $this->archivedAt = $archivedAt;

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    /** Действующий документ: опубликован, не в архиве. */
    public function isActive(): bool
    {
        return $this->isPublished() && !$this->isArchived();
    }

    /**
     * Просрочен ли документ для конкретного человека.
     *
     * Второе условие — про живой список аудитории: сотрудника, чья учётка
     * заведена уже после дедлайна, документ ждать не мог, и вешать на него
     * просрочку в день выхода на работу неправильно. Дата учётки здесь —
     * приближение даты приёма, точной у нас нет.
     */
    public function isOverdueFor(User $user): bool
    {
        if ($this->deadline === null || !$this->isActive()) {
            return false;
        }

        if ($this->deadline >= new \DateTimeImmutable('today')) {
            return false;
        }

        $userCreatedAt = $user->getCreatedAt();

        return $userCreatedAt === null || $userCreatedAt <= $this->deadline->setTime(23, 59, 59);
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): static
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    /**
     * @return Collection<int, AckDocumentFile>
     */
    public function getFiles(): Collection
    {
        return $this->files;
    }

    public function addFile(AckDocumentFile $file): static
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
            $file->setDocument($this);
        }

        return $this;
    }

    public function removeFile(AckDocumentFile $file): static
    {
        $this->files->removeElement($file);

        return $this;
    }
}
