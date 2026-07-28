<?php

declare(strict_types=1);

namespace App\Entity\Analytics\TKO;

use App\Entity\Organization\AbstractOrganization;
use App\Entity\Polygon\Polygon;
use App\Repository\Analytics\TKO\AnalyticsTkoVehicleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Справочник мусоровозов для аналитики ТКО.
 */
#[ORM\Entity(repositoryClass: AnalyticsTkoVehicleRepository::class)]
#[ORM\Table(name: 'analytics_tko_vehicle')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_tko_vehicle_license_number', columns: ['license_number'])]
#[ORM\Index(name: 'idx_analytics_tko_vehicle_polygon_id', columns: ['polygon_id'])]
#[ORM\Index(name: 'idx_analytics_tko_vehicle_organization_id', columns: ['organization_id'])]
class AnalyticsTkoVehicle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(name: 'license_number', length: 64)]
    private ?string $licenseNumber = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $volume = null;

    #[ORM\Column(name: 'compaction_ratio', type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $compactionRatio = null;

    #[ORM\ManyToOne(targetEntity: Polygon::class)]
    #[ORM\JoinColumn(name: 'polygon_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?Polygon $polygon = null;

    #[ORM\ManyToOne(targetEntity: AbstractOrganization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AbstractOrganization $organization = null;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

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

    public function getLicenseNumber(): ?string
    {
        return $this->licenseNumber;
    }

    public function setLicenseNumber(string $licenseNumber): static
    {
        $this->licenseNumber = mb_strtoupper(preg_replace('/\s+/u', '', $licenseNumber) ?? '');

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

    public function getPolygon(): ?Polygon
    {
        return $this->polygon;
    }

    public function setPolygon(Polygon $polygon): static
    {
        $this->polygon = $polygon;

        return $this;
    }

    public function getOrganization(): ?AbstractOrganization
    {
        return $this->organization;
    }

    public function setOrganization(AbstractOrganization $organization): static
    {
        $this->organization = $organization;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

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
}
