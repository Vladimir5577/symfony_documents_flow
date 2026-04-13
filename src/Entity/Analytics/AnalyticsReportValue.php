<?php

declare(strict_types=1);

namespace App\Entity\Analytics;

use App\Entity\User\User;
use App\Repository\Analytics\AnalyticsReportValueRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: AnalyticsReportValueRepository::class)]
#[ORM\Table(name: 'analytics_report_values')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_report_values_report_metric', columns: ['report_id', 'board_version_metric_id'])]
#[ORM\Index(name: 'idx_analytics_report_values_report_id', columns: ['report_id'])]
#[ORM\Index(name: 'idx_analytics_report_values_board_version_metric_id', columns: ['board_version_metric_id'])]
#[ORM\Index(name: 'idx_analytics_report_values_metric_report', columns: ['board_version_metric_id', 'report_id'])]
#[ORM\Index(name: 'idx_analytics_report_values_bvm_effective_at', columns: ['board_version_metric_id', 'effective_at'])]
#[ORM\Index(name: 'idx_analytics_report_values_effective_at', columns: ['effective_at'])]
#[ORM\HasLifecycleCallbacks]
class AnalyticsReportValue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AnalyticsReport::class, inversedBy: 'values')]
    #[ORM\JoinColumn(name: 'report_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?AnalyticsReport $report = null;

    #[ORM\ManyToOne(targetEntity: AnalyticsBoardVersionMetric::class)]
    #[ORM\JoinColumn(name: 'board_version_metric_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AnalyticsBoardVersionMetric $boardVersionMetric = null;

    #[ORM\Column(name: 'metric_name_snapshot', length: 255, nullable: true)]
    private ?string $metricNameSnapshot = null;

    #[ORM\Column(name: 'metric_unit_snapshot', length: 64, nullable: true)]
    private ?string $metricUnitSnapshot = null;

    #[ORM\Column(name: 'metric_type_snapshot', length: 64, nullable: true)]
    private ?string $metricTypeSnapshot = null;

    #[ORM\Column(name: 'value_number', type: Types::DECIMAL, precision: 20, scale: 4, nullable: true)]
    private ?string $valueNumber = null;

    #[ORM\Column(name: 'value_text', type: Types::TEXT, nullable: true)]
    private ?string $valueText = null;

    #[ORM\Column(name: 'value_bool', nullable: true)]
    private ?bool $valueBool = null;

    /** @var array<string, mixed>|list<mixed>|scalar|null Для select / multi-select / вложенных значений (PostgreSQL JSONB). */
    #[ORM\Column(name: 'value_json', type: Types::JSONB, nullable: true)]
    private mixed $valueJson = null;

    #[ORM\Column(name: 'effective_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $effectiveAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function ensureEffectiveAt(): void
    {
        $this->effectiveAt ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getBoardVersionMetric(): ?AnalyticsBoardVersionMetric
    {
        return $this->boardVersionMetric;
    }

    public function setBoardVersionMetric(?AnalyticsBoardVersionMetric $boardVersionMetric): static
    {
        $this->boardVersionMetric = $boardVersionMetric;

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

    public function getValueNumber(): ?string
    {
        return $this->valueNumber;
    }

    public function setValueNumber(?string $valueNumber): static
    {
        $this->valueNumber = $valueNumber;

        return $this;
    }

    public function getValueText(): ?string
    {
        return $this->valueText;
    }

    public function setValueText(?string $valueText): static
    {
        $this->valueText = $valueText;

        return $this;
    }

    public function getValueBool(): ?bool
    {
        return $this->valueBool;
    }

    public function setValueBool(?bool $valueBool): static
    {
        $this->valueBool = $valueBool;

        return $this;
    }

    public function getValueJson(): mixed
    {
        return $this->valueJson;
    }

    public function setValueJson(mixed $valueJson): static
    {
        $this->valueJson = $valueJson;

        return $this;
    }

    public function getEffectiveAt(): ?\DateTimeImmutable
    {
        return $this->effectiveAt;
    }

    public function setEffectiveAt(\DateTimeImmutable $effectiveAt): static
    {
        $this->effectiveAt = $effectiveAt;

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
}
