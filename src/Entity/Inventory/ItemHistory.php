<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Entity\User\User;
use App\Enum\Inventory\ItemHistoryAction;
use App\Enum\Inventory\ItemStatus;
use App\Repository\Inventory\ItemHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Журнал движений товара. Статус и владелец типизированы — по ним ищут
 * («что висело на уволенном сотруднике»). Смена организации и категории
 * пишется человекочитаемым текстом в comment: эти события читают глазами в ленте.
 */
#[ORM\Entity(repositoryClass: ItemHistoryRepository::class)]
#[ORM\Table(name: 'inventory_item_history')]
#[ORM\Index(name: 'idx_inventory_item_history_item_created', columns: ['item_id', 'created_at'])]
class ItemHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Item::class)]
    #[ORM\JoinColumn(name: 'item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Item $item;

    /**
     * Кто совершил действие. Может стать null, если пользователь удалён —
     * запись истории при этом сохраняется.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'changed_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $changedBy = null;

    #[ORM\Column(type: 'string', length: 32, enumType: ItemHistoryAction::class)]
    private ItemHistoryAction $action;

    #[ORM\Column(name: 'old_status', type: 'string', length: 32, enumType: ItemStatus::class, nullable: true)]
    private ?ItemStatus $oldStatus = null;

    #[ORM\Column(name: 'new_status', type: 'string', length: 32, enumType: ItemStatus::class, nullable: true)]
    private ?ItemStatus $newStatus = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'old_assigned_to_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $oldAssignedTo = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'new_assigned_to_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $newAssignedTo = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItem(): Item
    {
        return $this->item;
    }

    public function setItem(Item $item): static
    {
        $this->item = $item;

        return $this;
    }

    public function getChangedBy(): ?User
    {
        return $this->changedBy;
    }

    public function setChangedBy(?User $changedBy): static
    {
        $this->changedBy = $changedBy;

        return $this;
    }

    public function getAction(): ItemHistoryAction
    {
        return $this->action;
    }

    public function setAction(ItemHistoryAction $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getOldStatus(): ?ItemStatus
    {
        return $this->oldStatus;
    }

    public function setOldStatus(?ItemStatus $oldStatus): static
    {
        $this->oldStatus = $oldStatus;

        return $this;
    }

    public function getNewStatus(): ?ItemStatus
    {
        return $this->newStatus;
    }

    public function setNewStatus(?ItemStatus $newStatus): static
    {
        $this->newStatus = $newStatus;

        return $this;
    }

    public function getOldAssignedTo(): ?User
    {
        return $this->oldAssignedTo;
    }

    public function setOldAssignedTo(?User $oldAssignedTo): static
    {
        $this->oldAssignedTo = $oldAssignedTo;

        return $this;
    }

    public function getNewAssignedTo(): ?User
    {
        return $this->newAssignedTo;
    }

    public function setNewAssignedTo(?User $newAssignedTo): static
    {
        $this->newAssignedTo = $newAssignedTo;

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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
