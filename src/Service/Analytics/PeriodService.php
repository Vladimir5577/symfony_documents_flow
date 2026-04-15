<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsPeriod;
use App\Enum\Analytics\AnalyticsPeriodType;
use App\Repository\Analytics\AnalyticsPeriodRepository;
use Doctrine\ORM\EntityManagerInterface;

final class PeriodService
{
    private const REPORT_TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnalyticsPeriodRepository $repository,
    ) {
    }

    /**
     * @return AnalyticsPeriod[]
     */
    public function findAll(): array
    {
        return $this->repository->findAll([], ['startDate' => 'DESC']);
    }

    /**
     * @return AnalyticsPeriod[]
     */
    public function findByType(AnalyticsPeriodType $type): array
    {
        return $this->repository->findBy(['type' => $type], ['startDate' => 'DESC']);
    }

    /**
     * Создать период по номеру ISO-недели.
     */
    public function createByIsoWeek(int $isoYear, int $isoWeek): AnalyticsPeriod
    {
        $existing = $this->repository->findOneBy(['type' => AnalyticsPeriodType::Weekly, 'isoYear' => $isoYear, 'isoWeek' => $isoWeek]);
        if ($existing) {
            throw new \RuntimeException('Период ' . $isoYear . '-W' . str_pad((string) $isoWeek, 2, '0', STR_PAD_LEFT) . ' уже существует.');
        }

        $period = AnalyticsPeriod::forIsoWeek($isoYear, $isoWeek);
        $this->em->persist($period);
        $this->em->flush();

        return $period;
    }

    /**
     * Создать daily-период по дате.
     */
    public function createByDate(\DateTimeImmutable $date): AnalyticsPeriod
    {
        $existing = $this->repository->findOneBy(['type' => AnalyticsPeriodType::Daily, 'periodDate' => $date]);
        if ($existing) {
            throw new \RuntimeException('Период ' . $date->format('d.m.Y') . ' уже существует.');
        }

        $period = AnalyticsPeriod::forDate($date);
        $this->em->persist($period);
        $this->em->flush();

        return $period;
    }

    /**
     * Создать monthly-период по году и месяцу.
     */
    public function createByMonth(int $year, int $month): AnalyticsPeriod
    {
        $existing = $this->repository->findOneBy(['type' => AnalyticsPeriodType::Monthly, 'year' => $year, 'month' => $month]);
        if ($existing) {
            $monthNames = [1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель', 5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь'];
            throw new \RuntimeException('Период ' . ($monthNames[$month] ?? $month) . ' ' . $year . ' уже существует.');
        }

        $period = AnalyticsPeriod::forMonth($year, $month);
        $this->em->persist($period);
        $this->em->flush();

        return $period;
    }

    public function findById(int $id): ?AnalyticsPeriod
    {
        return $this->repository->find($id);
    }

    public function close(int $id): AnalyticsPeriod
    {
        $period = $this->repository->find($id);
        if (!$period) {
            throw new \RuntimeException('Период не найден.');
        }
        if ($period->isClosed()) {
            throw new \RuntimeException('Период уже закрыт.');
        }
        $period->setIsClosed(true);
        $this->em->flush();

        return $period;
    }

    public function open(int $id): AnalyticsPeriod
    {
        $period = $this->repository->find($id);
        if (!$period) {
            throw new \RuntimeException('Период не найден.');
        }
        if (!$period->isClosed()) {
            throw new \RuntimeException('Период не закрыт.');
        }
        $period->setIsClosed(false);
        $this->em->flush();

        return $period;
    }

    /**
     * Авто-генерация нескольких ближайших периодов указанного типа.
     */
    public function generateUpcomingPeriods(AnalyticsPeriodType $type, int $count = 4): int
    {
        $tz = new \DateTimeZone(self::REPORT_TIMEZONE);
        $now = new \DateTimeImmutable('now', $tz);
        $generated = 0;

        for ($i = 0; $i < $count; $i++) {
            $period = match ($type) {
                AnalyticsPeriodType::Daily => $this->findOrCreateDaily($now->modify(sprintf('+%d days', $i))),
                AnalyticsPeriodType::Weekly => $this->findOrCreateWeekly($now->modify(sprintf('+%d weeks', $i))),
                AnalyticsPeriodType::Monthly => $this->findOrCreateMonthly($now->modify(sprintf('+%d months', $i))),
            };
            if ($period) {
                $generated++;
            }
        }

        if ($generated > 0) {
            $this->em->flush();
        }

        return $generated;
    }

    private function findOrCreateDaily(\DateTimeImmutable $date): ?AnalyticsPeriod
    {
        $day = $date->setTime(0, 0, 0);
        $existing = $this->repository->findOneBy(['type' => AnalyticsPeriodType::Daily, 'periodDate' => $day]);
        if ($existing) {
            return null;
        }

        $period = AnalyticsPeriod::forDate($day);
        $this->em->persist($period);

        return $period;
    }

    private function findOrCreateWeekly(\DateTimeImmutable $date): ?AnalyticsPeriod
    {
        $isoYear = (int) $date->format('o');
        $isoWeek = (int) $date->format('W');

        $existing = $this->repository->findOneBy(['type' => AnalyticsPeriodType::Weekly, 'isoYear' => $isoYear, 'isoWeek' => $isoWeek]);
        if ($existing) {
            return null;
        }

        $period = AnalyticsPeriod::forIsoWeek($isoYear, $isoWeek);
        $this->em->persist($period);

        return $period;
    }

    private function findOrCreateMonthly(\DateTimeImmutable $date): ?AnalyticsPeriod
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');

        $existing = $this->repository->findOneBy(['type' => AnalyticsPeriodType::Monthly, 'year' => $year, 'month' => $month]);
        if ($existing) {
            return null;
        }

        $period = AnalyticsPeriod::forMonth($year, $month);
        $this->em->persist($period);

        return $period;
    }
}
