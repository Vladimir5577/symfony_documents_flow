<?php

declare(strict_types=1);

namespace App\Entity\AI;

use App\Entity\User\User;
use App\Repository\AI\AiChatMessageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: AiChatMessageRepository::class)]
#[ORM\Table(name: 'ai_chat_message')]
#[ORM\Index(name: 'idx_ai_chat_message_user_created', columns: ['user_id', 'created_at'])]
class AiChatMessage
{
    public const ROLE_USER      = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16)]
    private string $role;

    #[ORM\Column(type: Types::TEXT)]
    private string $content = '';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(name: 'tokens_in', type: Types::INTEGER, nullable: true)]
    private ?int $tokensIn = null;

    #[ORM\Column(name: 'tokens_out', type: Types::INTEGER, nullable: true)]
    private ?int $tokensOut = null;

    #[ORM\Column(length: 16, options: ['default' => self::STATUS_COMPLETED])]
    private string $status = self::STATUS_COMPLETED;

    #[ORM\Column(name: 'error_code', length: 64, nullable: true)]
    private ?string $errorCode = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    /** @var Collection<int, AiChatAttachment> */
    #[ORM\OneToMany(mappedBy: 'message', targetEntity: AiChatAttachment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $attachments;

    public function __construct()
    {
        $this->attachments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): static
    {
        $this->model = $model;
        return $this;
    }

    public function getTokensIn(): ?int
    {
        return $this->tokensIn;
    }

    public function setTokensIn(?int $tokensIn): static
    {
        $this->tokensIn = $tokensIn;
        return $this;
    }

    public function getTokensOut(): ?int
    {
        return $this->tokensOut;
    }

    public function setTokensOut(?int $tokensOut): static
    {
        $this->tokensOut = $tokensOut;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function setErrorCode(?string $errorCode): static
    {
        $this->errorCode = $errorCode;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, AiChatAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(AiChatAttachment $attachment): static
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
            $attachment->setMessage($this);
        }
        return $this;
    }

    public function removeAttachment(AiChatAttachment $attachment): static
    {
        $this->attachments->removeElement($attachment);
        return $this;
    }
}
