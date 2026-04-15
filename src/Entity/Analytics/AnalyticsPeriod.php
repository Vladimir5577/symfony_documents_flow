<?php

declare(strict_types=1);

namespace App\Entity\Analytics;

use App\Enum\Analytics\AnalyticsPeriodType;
use App\Repository\Analytics\AnalyticsPeriodRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Период аналитики: daily (конкретная дата), weekly (ISO-неделя), monthly (месяц).
 * Для weekly: iso_year/iso_week — год и номер недели по ISO 8601.
 * Для monthly: year/month — календарный год и месяц.
 * Для daily: period_date — конкретная дата.
 * start_date/end_date заполнены всегда (границы периода).
 */
#[ORM\Entity(repositoryClass: AnalyticsPeriodRepository::class)]
#[ORM\Table(name: 'analytics_periods')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_periods_daily', columns: ['period_date'])]
#[ORM\UniqueConstraint(name: 'uniq_analytics_periods_weekly', columns: ['iso_year', 'iso_week'])]
#[ORM\UniqueConstraint(name: 'uniq_analytics_periods_monthly', columns: ['year', 'month'])]
#[ORM\Index(name: 'idx_analytics_periods_start_date', columns: ['start_date'])]
#[ORM\Index(name: 'idx_analytics_periods_type', columns: ['type'])]
class AnalyticsPeriod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: AnalyticsPeriodType::class)]
    private AnalyticsPeriodType $type = AnalyticsPeriodType::Weekly;

    #[ORM\Column(name: 'iso_year', nullable: true)]
    private ?int $isoYear = null;

    #[ORM\Column(name: 'iso_week', nullable: true)]
    private ?int $isoWeek = null;

    #[ORM\Column(name: 'period_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $periodDate = null;

    #[ORM\Column(nullable: true)]
    private ?int $year = null;

    #[ORM\Column(nullable: true)]
    private ?int $month = null;

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

    // ─── Factories ───

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
        $self->type = AnalyticsPeriodType::Weekly;
        $self->isoYear = $isoYear;
        $self->isoWeek = $isoWeek;
        $self->startDate = $monday;
        $self->endDate = $monday->modify('+6 days');
        $self->description = sprintf('%d-W%02d', $isoYear, $isoWeek);

        return $self;
    }

    public static function forDate(\DateTimeImmutable $date): self
    {
        $self = new self();
        $self->type = AnalyticsPeriodType::Daily;
        $self->periodDate = $date;
        $self->startDate = $date;
        $self->endDate = $date;
        $self->description = $date->format('d.m.Y');

        return $self;
    }

    public static function forMonth(int $year, int $month): self
    {
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('month must be between 1 and 12.');
        }

        $firstDay = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
        $lastDay = $firstDay->modify('last day of this month');

        $self = new self();
        $self->type = AnalyticsPeriodType::Monthly;
        $self->year = $year;
        $self->month = $month;
        $self->startDate = $firstDay;
        $self->endDate = $lastDay;
        $monthNames = [
            1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
            5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
            9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
        ];
        $self->description = sprintf('%s %d', $monthNames[$month], $year);

        return $self;
    }

    // ─── Getters / Setters ───

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): AnalyticsPeriodType
    {
        return $this->type;
    }

    public function setType(AnalyticsPeriodType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getIsoYear(): ?int
    {
        return $this->isoYear;
    }

    public function setIsoYear(?int $isoYear): static
    {
        $this->isoYear = $isoYear;

        return $this;
    }

    public function getIsoWeek(): ?int
    {
        return $this->isoWeek;
    }

    public function setIsoWeek(?int $isoWeek): static
    {
        $this->isoWeek = $isoWeek;

        return $this;
    }

    public function getPeriodDate(): ?\DateTimeImmutable
    {
        return $this->periodDate;
    }

    public function setPeriodDate(?\DateTimeImmutable $periodDate): static
    {
        $this->periodDate = $periodDate;

        return $this;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(?int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getMonth(): ?int
    {
        return $this->month;
    }

    public function setMonth(?int $month): static
    {
        $this->month = $month;

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

    public function getDisplayLabel(): string
    {
        return match ($this->type) {
            AnalyticsPeriodType::Daily => $this->periodDate?->format('d.m.Y') ?? $this->getDescription() ?? '—',
            AnalyticsPeriodType::Weekly => $this->getWeeklyLabel(),
            AnalyticsPeriodType::Monthly => $this->description ?? '—',
        };
    }

    private function getWeeklyLabel(): string
    {
        $isoYear = $this->getIsoYear();
        $isoWeek = $this->getIsoWeek();
        $startDate = $this->getStartDate();
        $endDate = $this->getEndDate();

        if ($isoYear !== null && $isoWeek !== null && $startDate !== null && $endDate !== null) {
            return sprintf(
                '%d-W%02d (%s-%s)',
                $isoYear,
                $isoWeek,
                $startDate->format('d.m'),
                $endDate->format('d.m')
            );
        }

        return $this->getDescription() ?? '—';
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
