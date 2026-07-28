<?php

namespace App\Entity\Inventory;

use App\Enum\Inventory\DeviceStatus;
use App\Repository\Inventory\DeviceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: DeviceRepository::class)]
#[ORM\Table(name: 'inventory_device')]
#[ORM\Index(name: 'idx_inv_device_nomenclature', columns: ['nomenclature_id'])]
#[ORM\Index(name: 'idx_inv_device_ip', columns: ['ip_address'])]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt', timeAware: false, hardDelete: false)]
class Device
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: Nomenclature::class, inversedBy: 'devices')]
    #[ORM\JoinColumn(name: 'nomenclature_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?Nomenclature $nomenclature = null;

    #[ORM\Column(name: 'inventory_number', length: 100, nullable: true)]
    private ?string $inventoryNumber = null;

    #[ORM\Column(name: 'serial_number', length: 100, nullable: true)]
    private ?string $serialNumber = null;

    #[ORM\Column(
        name: 'ip_address',
        type: Types::STRING,
        length: 45,
        nullable: true,
        options: ['comment' => 'INET в БД'],
    )]
    private ?string $ipAddress = null;

    #[ORM\Column(name: 'mac_address', length: 17, nullable: true)]
    private ?string $macAddress = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $hostname = null;

    #[ORM\Column(length: 20, enumType: DeviceStatus::class, options: ['default' => 'in_service'])]
    private DeviceStatus $status = DeviceStatus::IN_SERVICE;

    #[ORM\Column(name: 'manual_location', length: 255, nullable: true)]
    private ?string $manualLocation = null;

    /** @var array<string, mixed>|list<mixed>|scalar|null */
    #[ORM\Column(type: Types::JSONB, nullable: true)]
    private mixed $specs = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /** @var Collection<int, DeviceCredential> */
    #[ORM\OneToMany(mappedBy: 'device', targetEntity: DeviceCredential::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $credentials;

    /** @var Collection<int, DeviceRepair> */
    #[ORM\OneToMany(mappedBy: 'device', targetEntity: DeviceRepair::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $repairs;

    public function __construct()
    {
        $this->credentials = new ArrayCollection();
        $this->repairs = new ArrayCollection();
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

    public function getNomenclature(): ?Nomenclature
    {
        return $this->nomenclature;
    }

    public function setNomenclature(?Nomenclature $nomenclature): static
    {
        $this->nomenclature = $nomenclature;

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

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function getMacAddress(): ?string
    {
        return $this->macAddress;
    }

    public function setMacAddress(?string $macAddress): static
    {
        $this->macAddress = $macAddress;

        return $this;
    }

    public function getHostname(): ?string
    {
        return $this->hostname;
    }

    public function setHostname(?string $hostname): static
    {
        $this->hostname = $hostname;

        return $this;
    }

    public function getStatus(): DeviceStatus
    {
        return $this->status;
    }

    public function setStatus(DeviceStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getManualLocation(): ?string
    {
        return $this->manualLocation;
    }

    public function setManualLocation(?string $manualLocation): static
    {
        $this->manualLocation = $manualLocation;

        return $this;
    }

    public function getSpecs(): mixed
    {
        return $this->specs;
    }

    public function setSpecs(mixed $specs): static
    {
        $this->specs = $specs;

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
     * @return Collection<int, DeviceCredential>
     */
    public function getCredentials(): Collection
    {
        return $this->credentials;
    }

    public function addCredential(DeviceCredential $credential): static
    {
        if (!$this->credentials->contains($credential)) {
            $this->credentials->add($credential);
            $credential->setDevice($this);
        }

        return $this;
    }

    public function removeCredential(DeviceCredential $credential): static
    {
        if ($this->credentials->removeElement($credential) && $credential->getDevice() === $this) {
            $credential->setDevice(null);
        }

        return $this;
    }

    /**
     * @return Collection<int, DeviceRepair>
     */
    public function getRepairs(): Collection
    {
        return $this->repairs;
    }

    public function addRepair(DeviceRepair $repair): static
    {
        if (!$this->repairs->contains($repair)) {
            $this->repairs->add($repair);
            $repair->setDevice($this);
        }

        return $this;
    }

    public function removeRepair(DeviceRepair $repair): static
    {
        if ($this->repairs->removeElement($repair) && $repair->getDevice() === $this) {
            $repair->setDevice(null);
        }

        return $this;
    }
}
