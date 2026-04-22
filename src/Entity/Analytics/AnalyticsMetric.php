<?php

declare(strict_types=1);

namespace App\Entity\Analytics;

use App\Enum\Analytics\AnalyticsMetricAggregationType;
use App\Repository\Analytics\AnalyticsMetricRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: AnalyticsMetricRepository::class)]
#[ORM\Table(name: 'analytics_metrics')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_metrics_business_key', columns: ['business_key'])]
class AnalyticsMetric
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'business_key', length: 128)]
    private ?string $businessKey = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 64)]
    private ?string $type = null;

    #[ORM\Column(length: 32)]
    private ?string $unit = null;

    #[ORM\Column(name: 'aggregation_type', type: Types::STRING, length: 16, enumType: AnalyticsMetricAggregationType::class)]
    private ?AnalyticsMetricAggregationType $aggregationType = null;

    #[ORM\Column(name: 'input_type', length: 32, nullable: true)]
    private ?string $inputType = null;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
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

    public function getBusinessKey(): ?string
    {
        return $this->businessKey;
    }

    public function setBusinessKey(string $businessKey): static
    {
        $this->businessKey = $businessKey;

        return $this;
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

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(string $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function getAggregationType(): ?AnalyticsMetricAggregationType
    {
        return $this->aggregationType;
    }

    public function setAggregationType(AnalyticsMetricAggregationType $aggregationType): static
    {
        $this->aggregationType = $aggregationType;

        return $this;
    }

    public function getInputType(): ?string
    {
        return $this->inputType;
    }

    public function setInputType(?string $inputType): static
    {
        $this->inputType = $inputType;

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
