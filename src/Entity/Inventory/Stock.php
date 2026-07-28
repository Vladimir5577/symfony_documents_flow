<?php

namespace App\Entity\Inventory;

use App\Entity\User\User;
use App\Repository\Inventory\StockRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StockRepository::class)]
#[ORM\Table(name: 'inventory_stock')]
// Doctrine cannot express PostgreSQL UNIQUE NULLS NOT DISTINCT. The owner migration creates the real constraint.
#[ORM\UniqueConstraint(
    name: 'uniq_inv_stock_key',
    columns: ['nomenclature_id', 'holder_user_id', 'holder_warehouse_id', 'managing_warehouse_id', 'room_id'],
)]
#[ORM\Index(name: 'idx_inv_stock_holder_user', columns: ['holder_user_id'])]
#[ORM\Index(name: 'idx_inv_stock_holder_wh', columns: ['holder_warehouse_id'])]
#[ORM\Index(name: 'idx_inv_stock_managing', columns: ['managing_warehouse_id'])]
#[ORM\Index(name: 'idx_inv_stock_nomenclature', columns: ['nomenclature_id'])]
#[ORM\Index(name: 'idx_inv_stock_room', columns: ['room_id'])]
class Stock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Nomenclature::class)]
    #[ORM\JoinColumn(name: 'nomenclature_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?Nomenclature $nomenclature = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'holder_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $holderUser = null;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'holder_warehouse_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Warehouse $holderWarehouse = null;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'managing_warehouse_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Warehouse $managingWarehouse = null;

    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(name: 'room_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Room $room = null;

    // Holder XOR, managing-warehouse and non-negative quantity CHECKs are owned by the database migration.
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private ?string $quantity = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getHolderUser(): ?User
    {
        return $this->holderUser;
    }

    public function setHolderUser(?User $holderUser): static
    {
        $this->holderUser = $holderUser;

        return $this;
    }

    public function getHolderWarehouse(): ?Warehouse
    {
        return $this->holderWarehouse;
    }

    public function setHolderWarehouse(?Warehouse $holderWarehouse): static
    {
        $this->holderWarehouse = $holderWarehouse;

        return $this;
    }

    public function getManagingWarehouse(): ?Warehouse
    {
        return $this->managingWarehouse;
    }

    public function setManagingWarehouse(?Warehouse $managingWarehouse): static
    {
        $this->managingWarehouse = $managingWarehouse;

        return $this;
    }

    public function getRoom(): ?Room
    {
        return $this->room;
    }

    public function setRoom(?Room $room): static
    {
        $this->room = $room;

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
}
