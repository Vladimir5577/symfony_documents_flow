<?php

declare(strict_types=1);

namespace App\Entity\Analytics;

use App\Repository\Analytics\AnalyticsPeriodRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Недельный период в смысле ISO 8601: пара (iso_year, iso_week) — «год недели» и номер недели 1…53.
 * Не путать с календарным годом дат внутри недели (на стыке декабрь/январь неделя может относиться к другому iso_year).
 */
#[ORM\Entity(repositoryClass: AnalyticsPeriodRepository::class)]
#[ORM\Table(name: 'analytics_periods')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_periods_iso_year_week', columns: ['iso_year', 'iso_week'])]
#[ORM\Index(name: 'idx_analytics_periods_start_date', columns: ['start_date'])]
class AnalyticsPeriod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'iso_year')]
    private ?int $isoYear = null;

    #[ORM\Column(name: 'iso_week')]
    private ?int $isoWeek = null;

    #[ORM\Column(name: 'start_date', type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(name: 'end_date', type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(name: 'is_closed', options: ['default' => false])]
    private bool $isClosed = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    public static function forIsoWeek(int $isoYear, int $isoWeek): self
    {
        if ($isoWeek < 1 || $isoWeek > 53) {
            throw new \InvalidArgumentException('iso_week must be between 1 and 53.');
        }

        $monday = (new \DateTimeImmutable())->setISODate($isoYear, $isoWeek);
        $actualIsoYear = (int) $monday->format('o');
        $actualIsoWeek = (int) $monday->format('W');
        if ($actualIsoYear !== $isoYear || $actualIsoWeek !== $isoWeek) {
            throw new \InvalidArgumentException(sprintf('Invalid ISO week: %d-W%02d.', $isoYear, $isoWeek));
        }

        $self = new self();
        $self->isoYear = $isoYear;
        $self->isoWeek = $isoWeek;
        $self->startDate = $monday;
        $self->endDate = $monday->modify('+6 days');
        $self->description = sprintf('%d-W%02d', $isoYear, $isoWeek);

        return $self;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIsoYear(): ?int
    {
        return $this->isoYear;
    }

    public function setIsoYear(int $isoYear): static
    {
        $this->isoYear = $isoYear;

        return $this;
    }

    public function getIsoWeek(): ?int
    {
        return $this->isoWeek;
    }

    public function setIsoWeek(int $isoWeek): static
    {
        $this->isoWeek = $isoWeek;

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function isClosed(): bool
    {
        return $this->isClosed;
    }

    public function setIsClosed(bool $isClosed): static
    {
        $this->isClosed = $isClosed;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

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
