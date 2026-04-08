<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsAggregatedData;
use App\Entity\Analytics\AnalyticsReport;
use App\Entity\Analytics\AnalyticsReportValue;
use App\Enum\Analytics\AnalyticsMetricAggregationType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Сервис для агрегации значений отчётов.
 * Когда отчёт утверждается, значения записываются в агрегированную таблицу.
 */
final class RecalculateAggregatesService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Записать данные одного утверждённого отчёта в агрегацию.
     */
    public function recalculateForReport(AnalyticsReport $report): void
    {
        // Удаляем старые агрегации для этого отчёта
        $existing = $this->em->getRepository(AnalyticsAggregatedData::class)->findBy(['report' => $report]);
        foreach ($existing as $old) {
            $this->em->remove($old);
        }

        // Группируем значения по метрике — одна строка агрегата на метрику (уникальность 5 полей)
        $valuesByMetric = [];
        foreach ($report->getValues() as $reportValue) {
            $boardMetric = $reportValue->getBoardVersionMetric();
            if (!$boardMetric) {
                continue;
            }
            $metric = $boardMetric->getMetric();
            if (!$metric) {
                continue;
            }
            $mId = $metric->getId();
            $valuesByMetric[$mId] ??= ['metric' => $metric, 'reportValue' => $reportValue];
        }

        foreach ($valuesByMetric as $data) {
            $metric = $data['metric'];
            $reportValue = $data['reportValue'];

            $aggregated = new AnalyticsAggregatedData();
            $aggregated->setReport($report);
            $aggregated->setOrganization($report->getOrganization());
            $aggregated->setBoard($report->getBoard());
            $aggregated->setPeriod($report->getPeriod());
            $aggregated->setMetric($metric);
            $aggregated->setAggregationType($metric->getAggregationType()->value);

            // Snapshot
            $aggregated->setMetricNameSnapshot($reportValue->getMetricNameSnapshot() ?: $metric->getName());
            $aggregated->setMetricUnitSnapshot($reportValue->getMetricUnitSnapshot() ?: $metric->getUnit());
            $aggregated->setMetricTypeSnapshot($reportValue->getMetricTypeSnapshot() ?: $metric->getType());

            $effectiveAt = $report->getSubmittedAt() ?: $report->getCreatedAt();
            $aggregated->setEffectiveAt($effectiveAt ?: new \DateTimeImmutable());

            // Пишем значение
            $this->copyValue($reportValue, $aggregated);

            $aggregated->setCalculatedAt(new \DateTimeImmutable());

            $this->em->persist($aggregated);
        }

        $this->em->flush();
    }

    private function copyValue(AnalyticsReportValue $source, AnalyticsAggregatedData $target): void
    {
        // numeric
        if ($source->getValueNumber() !== null) {
            $target->setValue($source->getValueNumber());
        } elseif ($source->getValueText() !== null) {
            $target->setValue($source->getValueText());
        } elseif ($source->getValueBool() !== null) {
            $target->setValueInt($source->getValueBool() ? 1 : 0);
        }
    }

    /**
     * Пересчитать агрегаты по всем утверждённым отчётам.
     */
    public function recalculateAll(): int
    {
        $reportRepo = $this->em->getRepository(AnalyticsReport::class);
        $approvedReports = $reportRepo->createQueryBuilder('r')
            ->andWhere('r.status = :status')
            ->setParameter('status', \App\Enum\Analytics\AnalyticsReportStatus::Approved)
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($approvedReports as $report) {
            $this->recalculateForReport($report);
            $count++;
        }

        return $count;
    }
}
