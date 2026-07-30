<?php

declare(strict_types=1);

namespace App\Entity\Analytics\TKO;

use App\Entity\Organization\AbstractOrganization;
use App\Entity\User\User;
use App\Enum\Analytics\AnalyticsTkoVehicleStatus;
use App\Repository\Analytics\TKO\AnalyticsTkoVehicleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Справочник мусоровозов для аналитики ТКО.
 */
#[ORM\Entity(repositoryClass: AnalyticsTkoVehicleRepository::class)]
#[ORM\Table(name: 'analytics_tko_vehicle')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_tko_vehicle_license_number', columns: ['license_number'])]
#[ORM\Index(name: 'idx_analytics_tko_vehicle_organization_id', columns: ['organization_id'])]
#[ORM\Index(name: 'idx_analytics_tko_vehicle_driver_id', columns: ['driver_id'])]
#[ORM\Index(name: 'idx_analytics_tko_vehicle_status', columns: ['status'])]
class AnalyticsTkoVehicle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'license_number', length: 64)]
    private ?string $licenseNumber = null;

    #[ORM\Column(length: 255)]
    private ?string $model = null;

    #[ORM\Column(name: 'type', length: 255)]
    private ?string $type = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $volume = null;

    #[ORM\Column(name: 'compaction_ratio', type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $compactionRatio = null;

    #[ORM\Column(name: 'fuel_consumption_norm', type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $fuelConsumptionNorm = null;

    #[ORM\Column(name: 'planned_write_off', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $plannedWriteOff = null;

    #[ORM\ManyToOne(targetEntity: AbstractOrganization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?AbstractOrganization $organization = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'driver_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $driver = null;

    #[ORM\Column(length: 20, enumType: AnalyticsTkoVehicleStatus::class, options: ['default' => 'active'])]
    private AnalyticsTkoVehicleStatus $status = AnalyticsTkoVehicleStatus::Active;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, AnalyticsTkoVehicleAttachment> */
    #[ORM\OneToMany(mappedBy: 'vehicle', targetEntity: AnalyticsTkoVehicleAttachment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $attachments;

    public function __construct()
    {
        $this->attachments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLicenseNumber(): ?string
    {
        return $this->licenseNumber;
    }

    public function setLicenseNumber(string $licenseNumber): static
    {
        $this->licenseNumber = mb_strtoupper(preg_replace('/\s+/u', '', $licenseNumber) ?? '');

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getVolume(): ?string
    {
        return $this->volume;
    }

    public function setVolume(?string $volume): static
    {
        $this->volume = $volume;

        return $this;
    }

    public function getCompactionRatio(): ?string
    {
        return $this->compactionRatio;
    }

    public function setCompactionRatio(?string $compactionRatio): static
    {
        $this->compactionRatio = $compactionRatio;

        return $this;
    }

    public function getFuelConsumptionNorm(): ?string
    {
        return $this->fuelConsumptionNorm;
    }

    public function setFuelConsumptionNorm(?string $fuelConsumptionNorm): static
    {
        $this->fuelConsumptionNorm = $fuelConsumptionNorm;

        return $this;
    }

    public function getPlannedWriteOff(): ?\DateTimeImmutable
    {
        return $this->plannedWriteOff;
    }

    public function setPlannedWriteOff(?\DateTimeImmutable $plannedWriteOff): static
    {
        $this->plannedWriteOff = $plannedWriteOff;

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

    public function getDriver(): ?User
    {
        return $this->driver;
    }

    public function setDriver(?User $driver): static
    {
        $this->driver = $driver;

        return $this;
    }

    public function getStatus(): AnalyticsTkoVehicleStatus
    {
        return $this->status;
    }

    public function setStatus(AnalyticsTkoVehicleStatus $status): static
    {
        $this->status = $status;

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

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): static
    {
        $this->updatedBy = $updatedBy;

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

    /** @return Collection<int, AnalyticsTkoVehicleAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(AnalyticsTkoVehicleAttachment $attachment): static
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
            $attachment->setVehicle($this);
        }

        return $this;
    }

    public function removeAttachment(AnalyticsTkoVehicleAttachment $attachment): static
    {
        $this->attachments->removeElement($attachment);

        return $this;
    }
}
