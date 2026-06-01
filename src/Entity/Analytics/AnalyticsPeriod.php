<?php

declare(strict_types=1);

namespace App\Entity\Analytics;

use App\Enum\Analytics\AnalyticsPeriodType;
use App\Repository\Analytics\AnalyticsPeriodRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AnalyticsPeriodRepository::class)]
#[ORM\Table(name: 'analytics_periods')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_periods_type_start_date', columns: ['type', 'start_date'])]
#[ORM\Index(name: 'idx_analytics_periods_start_date', columns: ['start_date'])]
class AnalyticsPeriod
{
    private const MONTH_NAMES = [
        1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
        5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
        9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
    ];

    private const MONTH_NAMES_GENITIVE = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
        5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
        9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: AnalyticsPeriodType::class)]
    private AnalyticsPeriodType $type = AnalyticsPeriodType::Weekly;

    #[ORM\Column(name: 'start_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(name: 'end_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endDate;

    public static function forIsoWeek(int $isoYear, int $isoWeek): self
    {
        if ($isoWeek < 1 || $isoWeek > 53) {
            throw new \InvalidArgumentException('iso_week must be between 1 and 53.');
        }

        $monday = (new \DateTimeImmutable())->setISODate($isoYear, $isoWeek);
        if ((int) $monday->format('o') !== $isoYear || (int) $monday->format('W') !== $isoWeek) {
            throw new \InvalidArgumentException(sprintf('Invalid ISO week: %d-W%02d.', $isoYear, $isoWeek));
        }

        $self = new self();
        $self->type = AnalyticsPeriodType::Weekly;
        $self->startDate = $monday;
        $self->endDate = $monday->modify('+6 days');

        return $self;
    }

    public static function forDate(\DateTimeImmutable $date): self
    {
        $day = $date->setTime(0, 0, 0);

        $self = new self();
        $self->type = AnalyticsPeriodType::Daily;
        $self->startDate = $day;
        $self->endDate = $day;

        return $self;
    }

    public static function forMonth(int $year, int $month): self
    {
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('month must be between 1 and 12.');
        }

        $firstDay = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));

        $self = new self();
        $self->type = AnalyticsPeriodType::Monthly;
        $self->startDate = $firstDay;
        $self->endDate = $firstDay->modify('last day of this month');

        return $self;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): AnalyticsPeriodType
    {
        return $this->type;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function getDisplayLabel(): string
    {
        return match ($this->type) {
            AnalyticsPeriodType::Daily => $this->startDate->format('d.m.Y'),
            AnalyticsPeriodType::Weekly => sprintf(
                '%s-W%s (%s-%s)',
                $this->startDate->format('o'),
                $this->startDate->format('W'),
                $this->startDate->format('d.m'),
                $this->endDate->format('d.m'),
            ),
            AnalyticsPeriodType::Monthly => sprintf(
                '%s %s',
                self::MONTH_NAMES[(int) $this->startDate->format('n')],
                $this->startDate->format('Y'),
            ),
        };
    }

    public function getHumanLabel(): string
    {
        $startDay = (int) $this->startDate->format('j');
        $startMonth = (int) $this->startDate->format('n');
        $startYear = (int) $this->startDate->format('Y');
        $endDay = (int) $this->endDate->format('j');
        $endMonth = (int) $this->endDate->format('n');
        $endYear = (int) $this->endDate->format('Y');

        return match ($this->type) {
            AnalyticsPeriodType::Daily => sprintf(
                '%d %s %d',
                $startDay,
                self::MONTH_NAMES_GENITIVE[$startMonth],
                $startYear,
            ),
            AnalyticsPeriodType::Weekly => match (true) {
                $startYear !== $endYear => sprintf(
                    '%d %s %d – %d %s %d',
                    $startDay,
                    self::MONTH_NAMES_GENITIVE[$startMonth],
                    $startYear,
                    $endDay,
                    self::MONTH_NAMES_GENITIVE[$endMonth],
                    $endYear,
                ),
                $startMonth !== $endMonth => sprintf(
                    '%d %s – %d %s %d',
                    $startDay,
                    self::MONTH_NAMES_GENITIVE[$startMonth],
                    $endDay,
                    self::MONTH_NAMES_GENITIVE[$endMonth],
                    $endYear,
                ),
                default => sprintf(
                    '%d–%d %s %d',
                    $startDay,
                    $endDay,
                    self::MONTH_NAMES_GENITIVE[$startMonth],
                    $startYear,
                ),
            },
            AnalyticsPeriodType::Monthly => sprintf(
                '%s %d',
                self::MONTH_NAMES[$startMonth],
                $startYear,
            ),
        };
    }
}
