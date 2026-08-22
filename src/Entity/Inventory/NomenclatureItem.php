<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Entity\Organization\AbstractOrganization;
use App\Entity\User\User;
use App\Enum\Inventory\ItemStatus;
use App\Repository\Inventory\NomenclatureItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Позиция номенклатуры — конкретная штука определённого вида.
 *
 * Всё, что отличает одну штуку от другой: инвентарный номер, серийник, статус,
 * цена, организация, владелец. Общее для вида — наименование и категория — живёт
 * в Nomenclature, поэтому десять одинаковых мониторов это десять строк здесь
 * и одна строка там.
 *
 * Владелец необязателен: штука без сотрудника лежит на складе, и таких большинство.
 */
#[ORM\Entity(repositoryClass: NomenclatureItemRepository::class)]
#[ORM\Table(name: 'inventory_nomenclature_item')]
#[ORM\UniqueConstraint(
    name: 'uniq_inventory_nomenclature_item_number',
    columns: ['organization_id', 'inventory_number'],
)]
/*
 * Инвентарный номер уникален внутри организации. Пара выше этого не обеспечивает,
 * когда организации нет: NULL-ы PostgreSQL считает различными, и номер 12345 можно
 * было бы завести сколько угодно раз. Партиальный индекс закрывает этот случай.
 * NULLS NOT DISTINCT не подходит — он заодно запретил бы вторую вещь без номера,
 * а безномерных (стёртая бирка, старая мебель) как раз много.
 *
 * Предикат записан ровно так, как его нормализует Postgres (каждое сравнение в своих
 * скобках). DBAL сверяет его строкой из pg_get_expr(): любое другое написание не
 * совпадёт, и индекс будет попадать в DROP/CREATE при каждом doctrine diff.
 */
#[ORM\UniqueConstraint(
    name: 'uniq_inventory_nomenclature_item_number_no_org',
    columns: ['inventory_number'],
    options: ['where' => '((organization_id IS NULL) AND (inventory_number IS NOT NULL))'],
)]
#[ORM\Index(name: 'idx_inventory_nomenclature_item_nomenclature', columns: ['nomenclature_id'])]
#[ORM\Index(name: 'idx_inventory_nomenclature_item_org_nom', columns: ['organization_id', 'nomenclature_id'])]
#[ORM\Index(name: 'idx_inventory_nomenclature_item_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_inventory_nomenclature_item_upd', columns: ['upd_id'])]
class NomenclatureItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Nomenclature::class)]
    #[ORM\JoinColumn(name: 'nomenclature_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Nomenclature $nomenclature;

    /**
     * Инвентарный номер уникален внутри организации: у разных организаций номера
     * могут совпадать.
     *
     * Может отсутствовать: при первичной инвентаризации попадаются предметы со стёртой
     * биркой и мебель, которой номер не присваивали.
     */
    #[ORM\Column(name: 'inventory_number', length: 64, nullable: true)]
    private ?string $inventoryNumber = null;

    #[ORM\Column(name: 'serial_number', length: 128, nullable: true)]
    private ?string $serialNumber = null;

    /**
     * NULL — организация ещё не проставлена.
     *
     * Такая штука не попадёт ни в один список, кроме списка главного администратора:
     * InventoryScope сверяет организацию через IN, а NULL не совпадает ни с чем.
     * Это то же «неразобранное», что и вид без категории.
     */
    #[ORM\ManyToOne(targetEntity: AbstractOrganization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?AbstractOrganization $organization = null;

    /**
     * null — штука не выдана никому, лежит на складе.
     *
     * RESTRICT, а не SET NULL: владелец не должен исчезать молча. Сейчас ограничение
     * инертно — User помечен Gedmo\SoftDeleteable(hardDelete: false), физического
     * удаления в приложении нет, — но страхует от правки сырым SQL.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $user = null;

    /**
     * Документ, по которому вещь приехала. NULL — документа нет и не будет:
     * вся первичная инвентаризация и старая мебель заводятся без него.
     *
     * RESTRICT: документ с позициями удалить нельзя, иначе история поступления
     * оборвалась бы молча.
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

    public function getNomenclature(): Nomenclature
    {
        return $this->nomenclature;
    }

    public function setNomenclature(Nomenclature $nomenclature): static
    {
        $this->nomenclature = $nomenclature;

        return $this;
    }

    /**
     * Наименование и категория — свойства вида, у штуки своих нет. Короткие
     * делегаты, чтобы вызывающий код не тянул цепочку через getNomenclature().
     */
    public function getName(): string
    {
        return $this->nomenclature->getName();
    }

    public function getCategory(): ?ItemCategory
    {
        return $this->nomenclature->getCategory();
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

    public function getOrganization(): ?AbstractOrganization
    {
        return $this->organization;
    }

    public function setOrganization(?AbstractOrganization $organization): static
    {
        $this->organization = $organization;

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
