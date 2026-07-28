<?php

namespace App\Entity\Inventory;

use App\Entity\User\User;
use App\Repository\Inventory\DocumentLineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentLineRepository::class)]
#[ORM\Table(name: 'inventory_document_line')]
#[ORM\Index(name: 'idx_inv_doc_line_document', columns: ['document_id'])]
#[ORM\Index(name: 'idx_inv_doc_line_nomenclature', columns: ['nomenclature_id'])]
class DocumentLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'document_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Document $document = null;

    #[ORM\ManyToOne(targetEntity: Nomenclature::class)]
    #[ORM\JoinColumn(name: 'nomenclature_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?Nomenclature $nomenclature = null;

    // CHECK quantity > 0 is owned by the database migration.
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private ?string $quantity = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)]
    private ?string $price = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'from_holder_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $fromHolderUser = null;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'from_holder_warehouse_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Warehouse $fromHolderWarehouse = null;

    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(name: 'from_room_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Room $fromRoom = null;

    /**
     * Контур-источник для строк с держателем-сотрудником (ddl_spec: from_managing_warehouse_id).
     * Авторезолвится при проведении, если строка остатка единственная; иначе указывается явно.
     */
    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'from_managing_warehouse_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Warehouse $fromManagingWarehouse = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'to_holder_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $toHolderUser = null;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'to_holder_warehouse_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Warehouse $toHolderWarehouse = null;

    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(name: 'to_room_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Room $toRoom = null;

    /**
     * Контур-приёмник для to-строк с держателем-сотрудником (ddl_spec: to_managing_warehouse_id);
     * дефолт — default_managing_warehouse_id документа, для transfer со склада — склад-источник.
     */
    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'to_managing_warehouse_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Warehouse $toManagingWarehouse = null;

    #[ORM\ManyToOne(targetEntity: Device::class)]
    #[ORM\JoinColumn(name: 'device_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Device $device = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $note = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function setDocument(?Document $document): static
    {
        $this->document = $document;

        return $this;
    }

    public function getNomenclature(): ?Nomenclature
    {
        return $this->nomenclature;
    }

    public function setNomenclature(?Nomenclature $nomenclature): static
    {
        $this->nomenclature = $nomenclature;

        return $this;
    }

    public function getQuantity(): ?string
    {
        return $this->quantity;
    }

    public function setQuantity(?string $quantity): static
    {
        $this->quantity = $quantity;

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

    public function getFromHolderUser(): ?User
    {
        return $this->fromHolderUser;
    }

    public function setFromHolderUser(?User $fromHolderUser): static
    {
        $this->fromHolderUser = $fromHolderUser;

        return $this;
    }

    public function getFromHolderWarehouse(): ?Warehouse
    {
        return $this->fromHolderWarehouse;
    }

    public function setFromHolderWarehouse(?Warehouse $fromHolderWarehouse): static
    {
        $this->fromHolderWarehouse = $fromHolderWarehouse;

        return $this;
    }

    public function getFromRoom(): ?Room
    {
        return $this->fromRoom;
    }

    public function setFromRoom(?Room $fromRoom): static
    {
        $this->fromRoom = $fromRoom;

        return $this;
    }

    public function getFromManagingWarehouse(): ?Warehouse
    {
        return $this->fromManagingWarehouse;
    }

    public function setFromManagingWarehouse(?Warehouse $fromManagingWarehouse): static
    {
        $this->fromManagingWarehouse = $fromManagingWarehouse;

        return $this;
    }

    public function getToHolderUser(): ?User
    {
        return $this->toHolderUser;
    }

    public function setToHolderUser(?User $toHolderUser): static
    {
        $this->toHolderUser = $toHolderUser;

        return $this;
    }

    public function getToHolderWarehouse(): ?Warehouse
    {
        return $this->toHolderWarehouse;
    }

    public function setToHolderWarehouse(?Warehouse $toHolderWarehouse): static
    {
        $this->toHolderWarehouse = $toHolderWarehouse;

        return $this;
    }

    public function getToRoom(): ?Room
    {
        return $this->toRoom;
    }

    public function setToRoom(?Room $toRoom): static
    {
        $this->toRoom = $toRoom;

        return $this;
    }

    public function getToManagingWarehouse(): ?Warehouse
    {
        return $this->toManagingWarehouse;
    }

    public function setToManagingWarehouse(?Warehouse $toManagingWarehouse): static
    {
        $this->toManagingWarehouse = $toManagingWarehouse;

        return $this;
    }

    public function getDevice(): ?Device
    {
        return $this->device;
    }

    public function setDevice(?Device $device): static
    {
        $this->device = $device;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }
}
