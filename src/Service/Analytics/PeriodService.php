<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsPeriod;
use App\Repository\Analytics\AnalyticsPeriodRepository;
use Doctrine\ORM\EntityManagerInterface;

final class PeriodService
{
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
     * Создать период по номеру ISO-недели.
     */
    public function createByIsoWeek(int $isoYear, int $isoWeek): AnalyticsPeriod
    {
        $existing = $this->repository->findOneBy(['isoYear' => $isoYear, 'isoWeek' => $isoWeek]);
        if ($existing) {
            throw new \RuntimeException('Период ' . $isoYear . '-W' . str_pad((string) $isoWeek, 2, '0', STR_PAD_LEFT) . ' уже существует.');
        }

        $period = AnalyticsPeriod::forIsoWeek($isoYear, $isoWeek);
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
     * Авто-генерация нескольких ближайших ISO-недель.
     */
    public function generateUpcomingWeeks(int $weeksAhead = 4): int
    {
        $now = new \DateTimeImmutable();
        $count = 0;

        for ($i = 0; $i < $weeksAhead; $i++) {
            $date = $now->modify(sprintf('+%d weeks', $i));
            $isoYear = (int) $date->format('o');
            $isoWeek = (int) $date->format('W');

            $existing = $this->repository->findOneBy(['isoYear' => $isoYear, 'isoWeek' => $isoWeek]);
            if ($existing) {
                continue;
            }

            $period = AnalyticsPeriod::forIsoWeek($isoYear, $isoWeek);
            $this->em->persist($period);
            $count++;
        }

        if ($count > 0) {
            $this->em->flush();
        }

        return $count;
    }
}
