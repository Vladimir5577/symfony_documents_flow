<?php

namespace App\Entity\Kanban;

use App\Entity\User\User;
use App\Enum\Kanban\KanbanCardActivityType;
use App\Repository\Kanban\KanbanCardActivityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: KanbanCardActivityRepository::class)]
#[ORM\Table(name: 'kanban_card_activity')]
#[ORM\Index(name: 'idx_card_activity_card_created', columns: ['card_id', 'created_at'])]
class KanbanCardActivity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: KanbanCard::class)]
    #[ORM\JoinColumn(name: 'card_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private KanbanCard $card;

    /**
     * Пользователь, совершивший действие. Может быть null — например, если автор
     * впоследствии удалён (onDelete SET NULL), запись истории при этом сохраняется.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(name: 'type', type: 'string', length: 40, enumType: KanbanCardActivityType::class)]
    private KanbanCardActivityType $type;

    /**
     * Человекочитаемое значение до изменения (например, название столбца, текст срока).
     * Для событий вроде "изменено описание" не заполняется — храним только факт.
     */
    #[ORM\Column(name: 'old_value', type: Types::TEXT, length: 1000, nullable: true)]
    private ?string $oldValue = null;

    /**
     * Человекочитаемое значение после изменения.
     */
    #[ORM\Column(name: 'new_value', type: Types::TEXT, length: 1000, nullable: true)]
    private ?string $newValue = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCard(): KanbanCard
    {
        return $this->card;
    }

    public function setCard(KanbanCard $card): static
    {
        $this->card = $card;
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

    public function getType(): KanbanCardActivityType
    {
        return $this->type;
    }

    public function setType(KanbanCardActivityType $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getOldValue(): ?string
    {
        return $this->oldValue;
    }

    public function setOldValue(?string $oldValue): static
    {
        $this->oldValue = $oldValue;
        return $this;
    }

    public function getNewValue(): ?string
    {
        return $this->newValue;
    }

    public function setNewValue(?string $newValue): static
    {
        $this->newValue = $newValue;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
