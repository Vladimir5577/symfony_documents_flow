<?php

declare(strict_types=1);

namespace App\Entity\Analytics;

use App\Entity\Organization\AbstractOrganization;
use App\Repository\Analytics\AnalyticsAggregatedDataRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AnalyticsAggregatedDataRepository::class)]
#[ORM\Table(name: 'analytics_aggregated_data')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_aggregated_data', columns: ['business_key', 'period_id', 'organization_id'])]
#[ORM\Index(name: 'idx_analytics_aggregated_data_period_org', columns: ['period_id', 'organization_id'])]
class AnalyticsAggregatedData
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'business_key', length: 128)]
    private ?string $businessKey = null;

    #[ORM\ManyToOne(targetEntity: AnalyticsPeriod::class)]
    #[ORM\JoinColumn(name: 'period_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AnalyticsPeriod $period = null;

    #[ORM\ManyToOne(targetEntity: AbstractOrganization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AbstractOrganization $organization = null;

    #[ORM\Column(name: 'aggregated_value_number', type: Types::DECIMAL, precision: 20, scale: 8)]
    private ?string $aggregatedValueNumber = null;

    #[ORM\Column(name: 'source_count')]
    private int $sourceCount = 0;

    #[ORM\Column(name: 'calculated_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $calculatedAt = null;

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

    public function getPeriod(): ?AnalyticsPeriod
    {
        return $this->period;
    }

    public function setPeriod(?AnalyticsPeriod $period): static
    {
        $this->period = $period;

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

    public function getAggregatedValueNumber(): ?string
    {
        return $this->aggregatedValueNumber;
    }

    public function setAggregatedValueNumber(string $aggregatedValueNumber): static
    {
        $this->aggregatedValueNumber = $aggregatedValueNumber;

        return $this;
    }

    public function getSourceCount(): int
    {
        return $this->sourceCount;
    }

    public function setSourceCount(int $sourceCount): static
    {
        $this->sourceCount = $sourceCount;

        return $this;
    }

    public function getCalculatedAt(): ?\DateTimeImmutable
    {
        return $this->calculatedAt;
    }

    public function setCalculatedAt(\DateTimeImmutable $calculatedAt): static
    {
        $this->calculatedAt = $calculatedAt;

        return $this;
    }
}
