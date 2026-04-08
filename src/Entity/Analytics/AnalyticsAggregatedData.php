<?php

declare(strict_types=1);

namespace App\Entity\Analytics;

use App\Entity\Organization\AbstractOrganization;
use App\Repository\Analytics\AnalyticsAggregatedDataRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AnalyticsAggregatedDataRepository::class)]
#[ORM\Table(name: 'analytics_aggregated_data')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_agg', columns: ['metric_id', 'period_id', 'organization_id', 'board_id', 'report_id'])]
#[ORM\Index(name: 'idx_analytics_agg_period_org', columns: ['period_id', 'organization_id'])]
#[ORM\Index(name: 'idx_analytics_agg_business_key_org', columns: ['business_key', 'organization_id'])]
#[ORM\Index(name: 'idx_analytics_agg_metric_org', columns: ['metric_id', 'organization_id'])]
#[ORM\Index(name: 'idx_analytics_agg_board', columns: ['board_id'])]
#[ORM\Index(name: 'idx_analytics_agg_report', columns: ['report_id'])]
#[ORM\Index(name: 'idx_analytics_agg_effective_at', columns: ['effective_at'])]
class AnalyticsAggregatedData
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AnalyticsMetric::class)]
    #[ORM\JoinColumn(name: 'metric_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AnalyticsMetric $metric = null;

    #[ORM\ManyToOne(targetEntity: AnalyticsBoard::class)]
    #[ORM\JoinColumn(name: 'board_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AnalyticsBoard $board = null;

    #[ORM\ManyToOne(targetEntity: AnalyticsReport::class)]
    #[ORM\JoinColumn(name: 'report_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?AnalyticsReport $report = null;

    #[ORM\ManyToOne(targetEntity: AnalyticsPeriod::class)]
    #[ORM\JoinColumn(name: 'period_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AnalyticsPeriod $period = null;

    #[ORM\ManyToOne(targetEntity: AbstractOrganization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AbstractOrganization $organization = null;

    #[ORM\Column(name: 'business_key', length: 128)]
    private ?string $businessKey = null;

    #[ORM\Column(name: 'metric_name_snapshot', length: 255, nullable: true)]
    private ?string $metricNameSnapshot = null;

    #[ORM\Column(name: 'metric_unit_snapshot', length: 64, nullable: true)]
    private ?string $metricUnitSnapshot = null;

    #[ORM\Column(name: 'metric_type_snapshot', length: 64, nullable: true)]
    private ?string $metricTypeSnapshot = null;

    #[ORM\Column(name: 'aggregation_type', length: 64)]
    private ?string $aggregationType = null;

    #[ORM\Column(name: 'effective_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $effectiveAt = null;

    #[ORM\Column(name: 'value', type: Types::DECIMAL, precision: 20, scale: 8, nullable: true)]
    private ?string $value = null;

    #[ORM\Column(name: 'value_int', nullable: true)]
    private ?int $valueInt = null;

    #[ORM\Column(name: 'aggregated_value_number', type: Types::DECIMAL, precision: 20, scale: 8, nullable: true)]
    private ?string $aggregatedValueNumber = null;

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
        if ($metric !== null) {
            $this->businessKey = $metric->getBusinessKey();
        }
        return $this;
    }

    public function getBoard(): ?AnalyticsBoard
    {
        return $this->board;
    }

    public function setBoard(?AnalyticsBoard $board): static
    {
        $this->board = $board;
        return $this;
    }

    public function getReport(): ?AnalyticsReport
    {
        return $this->report;
    }

    public function setReport(?AnalyticsReport $report): static
    {
        $this->report = $report;
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

    public function getBusinessKey(): ?string
    {
        return $this->businessKey;
    }

    public function setBusinessKey(string $businessKey): static
    {
        $this->businessKey = $businessKey;
        return $this;
    }

    public function getMetricNameSnapshot(): ?string
    {
        return $this->metricNameSnapshot;
    }

    public function setMetricNameSnapshot(?string $metricNameSnapshot): static
    {
        $this->metricNameSnapshot = $metricNameSnapshot;
        return $this;
    }

    public function getMetricUnitSnapshot(): ?string
    {
        return $this->metricUnitSnapshot;
    }

    public function setMetricUnitSnapshot(?string $metricUnitSnapshot): static
    {
        $this->metricUnitSnapshot = $metricUnitSnapshot;
        return $this;
    }

    public function getMetricTypeSnapshot(): ?string
    {
        return $this->metricTypeSnapshot;
    }

    public function setMetricTypeSnapshot(?string $metricTypeSnapshot): static
    {
        $this->metricTypeSnapshot = $metricTypeSnapshot;
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

    public function getEffectiveAt(): ?\DateTimeImmutable
    {
        return $this->effectiveAt;
    }

    public function setEffectiveAt(?\DateTimeImmutable $effectiveAt): static
    {
        $this->effectiveAt = $effectiveAt;
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

    public function getValueInt(): ?int
    {
        return $this->valueInt;
    }

    public function setValueInt(?int $valueInt): static
    {
        $this->valueInt = $valueInt;
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

    // Legacy alias
    public function getAggregatedValueNumber(): ?string
    {
        return $this->value ?? $this->aggregatedValueNumber;
    }

    public function setAggregatedValueNumber(string $aggregatedValueNumber): static
    {
        $this->aggregatedValueNumber = $aggregatedValueNumber;
        return $this;
    }
}
