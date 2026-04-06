<?php

declare(strict_types=1);

namespace App\Entity\Analytics;

use App\Enum\Analytics\AnalyticsBoardVersionStatus;
use App\Repository\Analytics\AnalyticsBoardVersionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: AnalyticsBoardVersionRepository::class)]
#[ORM\Table(name: 'analytics_board_versions')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_board_versions_board_version', columns: ['board_id', 'version_number'])]
#[ORM\Index(name: 'idx_analytics_board_versions_board_id', columns: ['board_id'])]
class AnalyticsBoardVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AnalyticsBoard::class, inversedBy: 'boardVersions')]
    #[ORM\JoinColumn(name: 'board_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AnalyticsBoard $board = null;

    #[ORM\Column(name: 'version_number')]
    private ?int $versionNumber = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: AnalyticsBoardVersionStatus::class)]
    private ?AnalyticsBoardVersionStatus $status = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, AnalyticsBoardVersionMetric> */
    #[ORM\OneToMany(targetEntity: AnalyticsBoardVersionMetric::class, mappedBy: 'boardVersion', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $versionMetrics;

    public function __construct()
    {
        $this->versionMetrics = new ArrayCollection();
        $this->status = AnalyticsBoardVersionStatus::Draft;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getVersionNumber(): ?int
    {
        return $this->versionNumber;
    }

    public function setVersionNumber(int $versionNumber): static
    {
        $this->versionNumber = $versionNumber;

        return $this;
    }

    public function getStatus(): ?AnalyticsBoardVersionStatus
    {
        return $this->status;
    }

    public function setStatus(AnalyticsBoardVersionStatus $status): static
    {
        $this->status = $status;

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

    /** @return Collection<int, AnalyticsBoardVersionMetric> */
    public function getVersionMetrics(): Collection
    {
        return $this->versionMetrics;
    }

    public function addVersionMetric(AnalyticsBoardVersionMetric $metric): static
    {
        if (!$this->versionMetrics->contains($metric)) {
            $this->versionMetrics->add($metric);
            $metric->setBoardVersion($this);
        }

        return $this;
    }

    public function removeVersionMetric(AnalyticsBoardVersionMetric $metric): static
    {
        $this->versionMetrics->removeElement($metric);

        return $this;
    }
}
