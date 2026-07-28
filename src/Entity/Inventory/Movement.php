<?php

namespace App\Entity\Inventory;

use App\Entity\User\User;
use App\Repository\Inventory\MovementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: MovementRepository::class)]
#[ORM\Table(name: 'inventory_movement')]
#[ORM\Index(name: 'idx_inv_movement_nomen_date', columns: ['nomenclature_id', 'operation_date'])]
#[ORM\Index(name: 'idx_inv_movement_document', columns: ['document_id'])]
#[ORM\Index(name: 'idx_inv_movement_device', columns: ['device_id'])]
#[ORM\Index(name: 'idx_inv_movement_holder_user', columns: ['holder_user_id'])]
#[ORM\Index(name: 'idx_inv_movement_holder_wh', columns: ['holder_warehouse_id'])]
class Movement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(name: 'document_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?Document $document = null;

    #[ORM\ManyToOne(targetEntity: DocumentLine::class)]
    #[ORM\JoinColumn(name: 'document_line_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?DocumentLine $documentLine = null;

    #[ORM\ManyToOne(targetEntity: Nomenclature::class)]
    #[ORM\JoinColumn(name: 'nomenclature_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?Nomenclature $nomenclature = null;

    // CHECK direction IN (-1, 1) is owned by the database migration.
    #[ORM\Column(type: Types::SMALLINT)]
    private ?int $direction = null;

    // CHECK quantity > 0 is owned by the database migration.
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private ?string $quantity = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'holder_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $holderUser = null;

    // Holder XOR and managing-warehouse CHECKs are owned by the database migration.
    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'holder_warehouse_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Warehouse $holderWarehouse = null;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'managing_warehouse_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Warehouse $managingWarehouse = null;

    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(name: 'room_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Room $room = null;

    #[ORM\Column(name: 'holder_department_id', nullable: true)]
    private ?int $holderDepartmentId = null;

    #[ORM\ManyToOne(targetEntity: Device::class)]
    #[ORM\JoinColumn(name: 'device_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Device $device = null;

    #[ORM\Column(name: 'operation_date', type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $operationDate = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

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

    public function getDocumentLine(): ?DocumentLine
    {
        return $this->documentLine;
    }

    public function setDocumentLine(?DocumentLine $documentLine): static
    {
        $this->documentLine = $documentLine;

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

    public function getDirection(): ?int
    {
        return $this->direction;
    }

    public function setDirection(int $direction): static
    {
        $this->direction = $direction;

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

    public function getHolderDepartmentId(): ?int
    {
        return $this->holderDepartmentId;
    }

    public function setHolderDepartmentId(?int $holderDepartmentId): static
    {
        $this->holderDepartmentId = $holderDepartmentId;

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

    public function getOperationDate(): ?\DateTimeImmutable
    {
        return $this->operationDate;
    }

    public function setOperationDate(?\DateTimeInterface $operationDate): static
    {
        $this->operationDate = $operationDate === null
            ? null
            : \DateTimeImmutable::createFromInterface($operationDate);

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
