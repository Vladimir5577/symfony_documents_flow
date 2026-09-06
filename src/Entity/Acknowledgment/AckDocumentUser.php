<?php

declare(strict_types=1);

namespace App\Entity\Acknowledgment;

use App\Entity\User\User;
use App\Enum\Acknowledgment\AckStatus;
use App\Repository\Acknowledgment\AckDocumentUserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Назначение и отметка об ознакомлении — одна строка, два смысла.
 *
 * audience=SELECTED: строка заводится при публикации, status = null — «назначен,
 * не отреагировал». audience=ALL: строки заранее нет вовсе, она появляется в
 * момент нажатия кнопки. Из-за этого «кто не ознакомился» для ALL считается
 * через LEFT JOIN от пользователей, а не выборкой отсюда, — см. репозиторий.
 */
#[ORM\Entity(repositoryClass: AckDocumentUserRepository::class)]
#[ORM\Table(name: 'ack_document_user')]
#[ORM\UniqueConstraint(name: 'uniq_ack_document_user', columns: ['document_id', 'user_id'])]
#[ORM\Index(name: 'idx_ack_document_user_document_status', columns: ['document_id', 'status'])]
#[ORM\Index(name: 'idx_ack_document_user_user_status', columns: ['user_id', 'status'])]
class AckDocumentUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AckDocument::class)]
    #[ORM\JoinColumn(name: 'document_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?AckDocument $document = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true, enumType: AckStatus::class)]
    private ?AckStatus $status = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(name: 'acted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $actedAt = null;

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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getStatus(): ?AckStatus
    {
        return $this->status;
    }

    public function setStatus(?AckStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getActedAt(): ?\DateTimeImmutable
    {
        return $this->actedAt;
    }

    public function setActedAt(?\DateTimeImmutable $actedAt): static
    {
        $this->actedAt = $actedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Обязанность закрыта: «ознакомлен» или «ознакомлен, не согласен». */
    public function isFinal(): bool
    {
        return $this->status !== null && $this->status->isFinal();
    }
}
