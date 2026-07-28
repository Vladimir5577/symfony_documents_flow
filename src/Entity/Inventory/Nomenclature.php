<?php

namespace App\Entity\Inventory;

use App\Entity\User\User;
use App\Enum\Inventory\Unit;
use App\Repository\Inventory\NomenclatureRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: NomenclatureRepository::class)]
#[ORM\Table(name: 'inventory_nomenclature')]
#[ORM\Index(name: 'idx_inv_nomenclature_category', columns: ['category_id'])]
#[ORM\Index(name: 'idx_inv_nomenclature_code_1c', columns: ['code_1c'])]
// The GIN(name gin_trgm_ops) index is PostgreSQL-specific and is created by the owner migration.
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt', timeAware: false, hardDelete: false)]
class Nomenclature
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 500)]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'nomenclatures')]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?Category $category = null;

    #[ORM\Column(length: 20, enumType: Unit::class, options: ['default' => 'PCS'])]
    private Unit $unit = Unit::PCS;

    #[ORM\Column(name: 'code_1c', length: 100, nullable: true)]
    private ?string $code1c = null;

    #[ORM\Column(name: 'is_consumable', options: ['default' => false])]
    private bool $isConsumable = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /** @var Collection<int, NomenclatureAlias> */
    #[ORM\OneToMany(mappedBy: 'nomenclature', targetEntity: NomenclatureAlias::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $aliases;

    /** @var Collection<int, Device> */
    #[ORM\OneToMany(mappedBy: 'nomenclature', targetEntity: Device::class)]
    private Collection $devices;

    public function __construct()
    {
        $this->aliases = new ArrayCollection();
        $this->devices = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getUnit(): Unit
    {
        return $this->unit;
    }

    public function setUnit(Unit $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function getCode1c(): ?string
    {
        return $this->code1c;
    }

    public function setCode1c(?string $code1c): static
    {
        $this->code1c = $code1c;

        return $this;
    }

    public function isConsumable(): bool
    {
        return $this->isConsumable;
    }

    public function setIsConsumable(bool $isConsumable): static
    {
        $this->isConsumable = $isConsumable;

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

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
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

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    /**
     * @return Collection<int, NomenclatureAlias>
     */
    public function getAliases(): Collection
    {
        return $this->aliases;
    }

    public function addAlias(NomenclatureAlias $alias): static
    {
        if (!$this->aliases->contains($alias)) {
            $this->aliases->add($alias);
            $alias->setNomenclature($this);
        }

        return $this;
    }

    public function removeAlias(NomenclatureAlias $alias): static
    {
        if ($this->aliases->removeElement($alias) && $alias->getNomenclature() === $this) {
            $alias->setNomenclature(null);
        }

        return $this;
    }

    /**
     * @return Collection<int, Device>
     */
    public function getDevices(): Collection
    {
        return $this->devices;
    }

    public function addDevice(Device $device): static
    {
        if (!$this->devices->contains($device)) {
            $this->devices->add($device);
            $device->setNomenclature($this);
        }

        return $this;
    }

    public function removeDevice(Device $device): static
    {
        if ($this->devices->removeElement($device) && $device->getNomenclature() === $this) {
            $device->setNomenclature(null);
        }

        return $this;
    }
}
