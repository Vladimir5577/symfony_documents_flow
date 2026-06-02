<?php

declare(strict_types=1);

namespace App\Entity\Polygon;

use App\Repository\Polygon\PolygonRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PolygonRepository::class)]
#[ORM\Table(name: 'polygon')]
#[ORM\UniqueConstraint(name: 'uniq_polygon_name', columns: ['name'])]
class Polygon
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(name: 'gps_lat', type: Types::FLOAT, nullable: true)]
    private ?float $gpsLat = null;

    #[ORM\Column(name: 'gps_lng', type: Types::FLOAT, nullable: true)]
    private ?float $gpsLng = null;

    #[ORM\Column(name: 'contact_name', length: 255, nullable: true)]
    private ?string $contactName = null;

    #[ORM\Column(name: 'contact_phone', length: 64, nullable: true)]
    private ?string $contactPhone = null;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getGpsLat(): ?float
    {
        return $this->gpsLat;
    }

    public function setGpsLat(?float $gpsLat): static
    {
        $this->gpsLat = $gpsLat;

        return $this;
    }

    public function getGpsLng(): ?float
    {
        return $this->gpsLng;
    }

    public function setGpsLng(?float $gpsLng): static
    {
        $this->gpsLng = $gpsLng;

        return $this;
    }

    public function getContactName(): ?string
    {
        return $this->contactName;
    }

    public function setContactName(?string $contactName): static
    {
        $this->contactName = $contactName;

        return $this;
    }

    public function getContactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function setContactPhone(?string $contactPhone): static
    {
        $this->contactPhone = $contactPhone;

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
}
