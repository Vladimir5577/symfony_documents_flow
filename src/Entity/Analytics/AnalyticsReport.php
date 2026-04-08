<?php

declare(strict_types=1);

namespace App\Entity\Analytics;

use App\Entity\Organization\AbstractOrganization;
use App\Entity\User\User;
use App\Enum\Analytics\AnalyticsReportStatus;
use App\Repository\Analytics\AnalyticsReportRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: AnalyticsReportRepository::class)]
#[ORM\Table(name: 'analytics_reports')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_reports_org_board_period', columns: ['organization_id', 'board_id', 'period_id'])]
#[ORM\Index(name: 'idx_analytics_reports_period_id', columns: ['period_id'])]
#[ORM\Index(name: 'idx_analytics_reports_organization_id', columns: ['organization_id'])]
#[ORM\Index(name: 'idx_analytics_reports_organization_period', columns: ['organization_id', 'period_id'])]
#[ORM\Index(name: 'idx_analytics_reports_board_id', columns: ['board_id'])]
#[ORM\Index(name: 'idx_analytics_reports_status', columns: ['status'])]
#[ORM\Index(name: 'idx_analytics_reports_status_period', columns: ['status', 'period_id'])]
class AnalyticsReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AbstractOrganization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AbstractOrganization $organization = null;

    #[ORM\ManyToOne(targetEntity: AnalyticsBoard::class)]
    #[ORM\JoinColumn(name: 'board_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AnalyticsBoard $board = null;

    #[ORM\ManyToOne(targetEntity: AnalyticsBoardVersion::class)]
    #[ORM\JoinColumn(name: 'board_version_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AnalyticsBoardVersion $boardVersion = null;

    #[ORM\ManyToOne(targetEntity: AnalyticsPeriod::class)]
    #[ORM\JoinColumn(name: 'period_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AnalyticsPeriod $period = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?User $createdBy = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: AnalyticsReportStatus::class)]
    private ?AnalyticsReportStatus $status = null;

    #[ORM\Column(name: 'is_complete', options: ['default' => false])]
    private bool $isComplete = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(name: 'approved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'approved_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $approvedBy = null;

    /** @var Collection<int, AnalyticsReportValue> */
    #[ORM\OneToMany(targetEntity: AnalyticsReportValue::class, mappedBy: 'report', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $values;

    public function __construct()
    {
        $this->values = new ArrayCollection();
        $this->status = AnalyticsReportStatus::Draft;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getBoard(): ?AnalyticsBoard
    {
        return $this->board;
    }

    public function setBoard(?AnalyticsBoard $board): static
    {
        $this->board = $board;

        return $this;
    }

    public function getBoardVersion(): ?AnalyticsBoardVersion
    {
        return $this->boardVersion;
    }

    public function setBoardVersion(?AnalyticsBoardVersion $boardVersion): static
    {
        $this->boardVersion = $boardVersion;
        $this->board = $boardVersion?->getBoard();

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

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getStatus(): ?AnalyticsReportStatus
    {
        return $this->status;
    }

    public function setStatus(AnalyticsReportStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isComplete(): bool
    {
        return $this->isComplete;
    }

    public function setIsComplete(bool $isComplete): static
    {
        $this->isComplete = $isComplete;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

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

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(?\DateTimeImmutable $submittedAt): static
    {
        $this->submittedAt = $submittedAt;

        return $this;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeImmutable $approvedAt): static
    {
        $this->approvedAt = $approvedAt;

        return $this;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function setApprovedBy(?User $approvedBy): static
    {
        $this->approvedBy = $approvedBy;

        return $this;
    }

    /** @return Collection<int, AnalyticsReportValue> */
    public function getValues(): Collection
    {
        return $this->values;
    }

    public function addValue(AnalyticsReportValue $value): static
    {
        if (!$this->values->contains($value)) {
            $this->values->add($value);
            $value->setReport($this);
        }

        return $this;
    }

    public function removeValue(AnalyticsReportValue $value): static
    {
        $this->values->removeElement($value);

        return $this;
    }
}
