<?php

declare(strict_types=1);

namespace App\Entity\Analytics;

use App\Entity\Organization\AbstractOrganization;
use App\Repository\Analytics\AnalyticsAggregatedDataRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Агрегированные данные: одно значение на (метрика, период, организация).
 * Вычисляется при утверждении отчёта по правилу aggregation_type метрики.
 */
#[ORM\Entity(repositoryClass: AnalyticsAggregatedDataRepository::class)]
#[ORM\Table(name: 'analytics_aggregated_data')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_agg', columns: ['metric_id', 'period_id', 'organization_id'])]
#[ORM\Index(name: 'idx_analytics_agg_period_org', columns: ['period_id', 'organization_id'])]
#[ORM\Index(name: 'idx_analytics_agg_metric_org', columns: ['metric_id', 'organization_id'])]
class AnalyticsAggregatedData
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AnalyticsMetric::class)]
    #[ORM\JoinColumn(name: 'metric_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AnalyticsMetric $metric = null;

    #[ORM\ManyToOne(targetEntity: AnalyticsPeriod::class)]
    #[ORM\JoinColumn(name: 'period_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AnalyticsPeriod $period = null;

    #[ORM\ManyToOne(targetEntity: AbstractOrganization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AbstractOrganization $organization = null;

    #[ORM\Column(name: 'aggregation_type', length: 64)]
    private ?string $aggregationType = null;

    #[ORM\Column(name: 'value', type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $value = null;

    #[ORM\Column(name: 'source_count')]
    private int $sourceCount = 0;

    #[ORM\Column(name: 'calculated_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $calculatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMetric(): ?AnalyticsMetric
    {
        return $this->metric;
    }

    public function setMetric(?AnalyticsMetric $metric): static
    {
        $this->metric = $metric;
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

    public function getAggregationType(): ?string
    {
        return $this->aggregationType;
    }

    public function setAggregationType(string $aggregationType): static
    {
        $this->aggregationType = $aggregationType;
        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): static
    {
        $this->value = $value;
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

    public function setCalculatedAt(?\DateTimeImmutable $calculatedAt): static
    {
        $this->calculatedAt = $calculatedAt;
        return $this;
    }
}
