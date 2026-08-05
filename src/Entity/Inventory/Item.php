<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Entity\Organization\AbstractOrganization;
use App\Entity\User\User;
use App\Enum\Inventory\ItemStatus;
use App\Repository\Inventory\ItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: ItemRepository::class)]
#[ORM\Table(name: 'inventory_item')]
#[ORM\UniqueConstraint(
    name: 'uniq_inventory_item_number',
    columns: ['organization_id', 'inventory_number'],
)]
#[ORM\Index(name: 'idx_inventory_item_org_category', columns: ['organization_id', 'category_id'])]
#[ORM\Index(name: 'idx_inventory_item_assigned_to', columns: ['assigned_to_id'])]
#[ORM\Index(name: 'idx_inventory_item_upd', columns: ['upd_id'])]
class Item
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    /**
     * Инвентарный номер уникален внутри организации: у разных организаций номера могут совпадать.
     *
     * Может отсутствовать: при первичной инвентаризации попадаются предметы со стёртой
     * биркой и мебель, которой номер не присваивали. NULL-ы в уникальном индексе
     * PostgreSQL считает различными, поэтому безномерных предметов может быть сколько
     * угодно, а заполненные номера остаются уникальными.
     */
    #[ORM\Column(name: 'inventory_number', length: 64, nullable: true)]
    private ?string $inventoryNumber = null;

    #[ORM\Column(name: 'serial_number', length: 128, nullable: true)]
    private ?string $serialNumber = null;

    /**
     * NULL — категорию ещё не разобрали. Это честное «неизвестно», а не отнесение
     * к какому-нибудь «прочему»: две ситуации нужно различать, чтобы перед закрытием
     * инвентаризации можно было потребовать разобрать остатки (фильтр no_category).
     * Такие товары видит админ организации, ответственные за категории — нет.
     */
    #[ORM\ManyToOne(targetEntity: ItemCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?ItemCategory $category = null;

    #[ORM\ManyToOne(targetEntity: AbstractOrganization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AbstractOrganization $organization;

    /**
     * null — товар не присвоен никому.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_to_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedTo = null;

    /**
     * Документ, по которому вещь приехала. NULL — документа нет и не будет:
     * вся первичная инвентаризация и старая мебель заводятся без него, поэтому
     * поле необязательное, как категория и инвентарный номер.
     *
     * RESTRICT: документ с позициями удалить нельзя, иначе история поступления
     * оборвалась бы молча — так же, как защищены категория с имуществом
     * и организация с товарами.
     */
    #[ORM\ManyToOne(targetEntity: Upd::class)]
    #[ORM\JoinColumn(name: 'upd_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Upd $upd = null;

    #[ORM\Column(type: 'string', length: 32, enumType: ItemStatus::class, options: ['default' => 'in_stock'])]
    private ItemStatus $status = ItemStatus::IN_STOCK;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $price = null;

    #[ORM\Column(name: 'purchased_at', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $purchasedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getInventoryNumber(): ?string
    {
        return $this->inventoryNumber;
    }

    public function setInventoryNumber(?string $inventoryNumber): static
    {
        $this->inventoryNumber = $inventoryNumber;

        return $this;
    }

    public function getSerialNumber(): ?string
    {
        return $this->serialNumber;
    }

    public function setSerialNumber(?string $serialNumber): static
    {
        $this->serialNumber = $serialNumber;

        return $this;
    }

    public function getCategory(): ?ItemCategory
    {
        return $this->category;
    }

    public function setCategory(?ItemCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getOrganization(): AbstractOrganization
    {
        return $this->organization;
    }

    public function setOrganization(AbstractOrganization $organization): static
    {
        $this->organization = $organization;

        return $this;
    }

    public function getAssignedTo(): ?User
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?User $assignedTo): static
    {
        $this->assignedTo = $assignedTo;

        return $this;
    }

    public function getUpd(): ?Upd
    {
        return $this->upd;
    }

    public function setUpd(?Upd $upd): static
    {
        $this->upd = $upd;

        return $this;
    }

    public function getStatus(): ItemStatus
    {
        return $this->status;
    }

    public function setStatus(ItemStatus $status): static
    {
        $this->status = $status;

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

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getPurchasedAt(): ?\DateTimeImmutable
    {
        return $this->purchasedAt;
    }

    public function setPurchasedAt(?\DateTimeImmutable $purchasedAt): static
    {
        $this->purchasedAt = $purchasedAt;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): static
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }
}
