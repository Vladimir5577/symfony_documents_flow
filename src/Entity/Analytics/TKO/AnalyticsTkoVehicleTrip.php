<?php

declare(strict_types=1);

namespace App\Entity\Analytics\TKO;

use App\Repository\Analytics\TKO\AnalyticsTkoVehicleTripRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ходка мусоровоза: вес разгрузки за дату и порядковый номер ходки в этот день.
 */
#[ORM\Entity(repositoryClass: AnalyticsTkoVehicleTripRepository::class)]
#[ORM\Table(name: 'analytics_tko_vehicle_trip')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_tko_vehicle_trip', columns: ['vehicle_id', 'trip_date', 'trip_number'])]
#[ORM\Index(name: 'idx_analytics_tko_vehicle_trip_trip_date', columns: ['trip_date'])]
#[ORM\Index(name: 'idx_analytics_tko_vehicle_trip_vehicle_id', columns: ['vehicle_id'])]
class AnalyticsTkoVehicleTrip
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'trip_date', type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $tripDate = null;

    #[ORM\ManyToOne(targetEntity: AnalyticsTkoVehicle::class)]
    #[ORM\JoinColumn(name: 'vehicle_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AnalyticsTkoVehicle $vehicle = null;

    #[ORM\Column(name: 'trip_number', type: Types::INTEGER)]
    private ?int $tripNumber = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private ?string $weight = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTripDate(): ?\DateTimeImmutable
    {
        return $this->tripDate;
    }

    public function setTripDate(\DateTimeImmutable $tripDate): static
    {
        $this->tripDate = $tripDate;

        return $this;
    }

    public function getVehicle(): ?AnalyticsTkoVehicle
    {
        return $this->vehicle;
    }

    public function setVehicle(AnalyticsTkoVehicle $vehicle): static
    {
        $this->vehicle = $vehicle;

        return $this;
    }

    public function getTripNumber(): ?int
    {
        return $this->tripNumber;
    }

    public function setTripNumber(int $tripNumber): static
    {
        $this->tripNumber = $tripNumber;

        return $this;
    }

    public function getWeight(): ?string
    {
        return $this->weight;
    }

    public function setWeight(string $weight): static
    {
        $this->weight = $weight;

        return $this;
    }
}
