<?php

declare(strict_types=1);

namespace App\Entity\Analytics;

use App\Repository\Analytics\AnalyticsBoardVersionMetricRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AnalyticsBoardVersionMetricRepository::class)]
#[ORM\Table(name: 'analytics_board_version_metrics')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_board_version_metrics', columns: ['board_version_id', 'metric_id'])]
#[ORM\Index(name: 'idx_analytics_board_version_metrics_board_version', columns: ['board_version_id'])]
#[ORM\Index(name: 'idx_analytics_board_version_metrics_metric', columns: ['metric_id'])]
class AnalyticsBoardVersionMetric
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AnalyticsBoardVersion::class, inversedBy: 'versionMetrics')]
    #[ORM\JoinColumn(name: 'board_version_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?AnalyticsBoardVersion $boardVersion = null;

    #[ORM\ManyToOne(targetEntity: AnalyticsMetric::class)]
    #[ORM\JoinColumn(name: 'metric_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AnalyticsMetric $metric = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(name: 'is_required', options: ['default' => false])]
    private bool $isRequired = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBoardVersion(): ?AnalyticsBoardVersion
    {
        return $this->boardVersion;
    }

    public function setBoardVersion(?AnalyticsBoardVersion $boardVersion): static
    {
        $this->boardVersion = $boardVersion;

        return $this;
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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function setIsRequired(bool $isRequired): static
    {
        $this->isRequired = $isRequired;

        return $this;
    }
}
