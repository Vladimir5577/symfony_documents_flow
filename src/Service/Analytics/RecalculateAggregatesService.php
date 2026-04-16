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
 * Когда отчёт утверждается, значения агрегируются по связке (метрика, период, организация).
 */
final class RecalculateAggregatesService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Пересчитать агрегаты для одного утверждённого отчёта.
     * Агрегирует по связке (metric_id, period_id, organization_id).
     */
    public function recalculateForReport(AnalyticsReport $report): void
    {
        $period = $report->getPeriod();
        $organization = $report->getOrganization();
        if (!$period || !$organization) {
            return;
        }

        // Группируем значения отчёта по метрике.
        // Берём самое свежее значение метрики, чтобы не использовать устаревшее при наличии дублей.
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
            if (!isset($valuesByMetric[$mId])) {
                $valuesByMetric[$mId] = ['metric' => $metric, 'reportValue' => $reportValue];
                continue;
            }

            /** @var AnalyticsReportValue $existingReportValue */
            $existingReportValue = $valuesByMetric[$mId]['reportValue'];
            $existingEffectiveAt = $existingReportValue->getEffectiveAt();
            $candidateEffectiveAt = $reportValue->getEffectiveAt();

            $shouldReplace = false;
            if ($existingEffectiveAt === null && $candidateEffectiveAt !== null) {
                $shouldReplace = true;
            } elseif ($existingEffectiveAt !== null && $candidateEffectiveAt !== null && $candidateEffectiveAt > $existingEffectiveAt) {
                $shouldReplace = true;
            } elseif (
                $existingEffectiveAt == $candidateEffectiveAt
                && ($existingReportValue->getId() ?? 0) < ($reportValue->getId() ?? 0)
            ) {
                $shouldReplace = true;
            }

            if ($shouldReplace) {
                $valuesByMetric[$mId] = ['metric' => $metric, 'reportValue' => $reportValue];
            }
        }

        foreach ($valuesByMetric as $data) {
            $metric = $data['metric'];
            $reportValue = $data['reportValue'];

            $this->upsertAggregatedValue($metric, $reportValue, $period, $organization, $report);
        }

        $this->em->flush();
    }

    /**
     * Upsert одной строки агрегата для (metric, period, organization).
     * Если агрегат уже есть — пересчитываем с учётом нового отчёта.
     */
    private function upsertAggregatedValue(
        object $metric,
        AnalyticsReportValue $reportValue,
        object $period,
        object $organization,
        AnalyticsReport $report,
    ): void {
        $repo = $this->em->getRepository(AnalyticsAggregatedData::class);
        $existing = $repo->findOneBy([
            'metric' => $metric,
            'period' => $period,
            'organization' => $organization,
        ]);

        $numericValue = $this->extractNumericValue($reportValue);
        if ($numericValue === null) {
            return;
        }

        if ($existing) {
            // Добавляем новое значение к существующему агрегату
            $aggregationType = $metric->getAggregationType();
            $currentValue = (float) $existing->getValue();
            $sourceCount = $existing->getSourceCount() + 1;

            $newValue = match ($aggregationType) {
                AnalyticsMetricAggregationType::Sum => $currentValue + $numericValue,
                AnalyticsMetricAggregationType::Avg => (($currentValue * $existing->getSourceCount()) + $numericValue) / $sourceCount,
                AnalyticsMetricAggregationType::Min => min($currentValue, $numericValue),
                AnalyticsMetricAggregationType::Max => max($currentValue, $numericValue),
                AnalyticsMetricAggregationType::Last => $numericValue, // last = берём последнее по effective_at
                default => $numericValue,
            };

            $existing->setValue(number_format($newValue, 4, '.', ''));
            $existing->setSourceCount($sourceCount);

            if ($aggregationType === AnalyticsMetricAggregationType::Last) {
                $reportEffectiveAt = $report->getSubmittedAt() ?: $report->getCreatedAt();
                if ($reportEffectiveAt && (!$existing->getEffectiveAt() || $reportEffectiveAt > $existing->getEffectiveAt())) {
                    $existing->setEffectiveAt($reportEffectiveAt);
                }
            }

            $existing->setCalculatedAt(new \DateTimeImmutable());
        } else {
            $aggregated = new AnalyticsAggregatedData();
            $aggregated->setMetric($metric);
            $aggregated->setPeriod($period);
            $aggregated->setOrganization($organization);
            $aggregated->setAggregationType($metric->getAggregationType()->value);
            $aggregated->setMetricNameSnapshot($reportValue->getMetricNameSnapshot() ?: $metric->getName());
            $aggregated->setMetricUnitSnapshot($reportValue->getMetricUnitSnapshot() ?: $metric->getUnit());
            $aggregated->setMetricTypeSnapshot($reportValue->getMetricTypeSnapshot() ?: $metric->getType());

            $effectiveAt = $report->getSubmittedAt() ?: $report->getCreatedAt();
            $aggregated->setEffectiveAt($effectiveAt ?: new \DateTimeImmutable());

            $aggregated->setValue(number_format($numericValue, 4, '.', ''));
            $aggregated->setSourceCount(1);
            $aggregated->setCalculatedAt(new \DateTimeImmutable());

            $this->em->persist($aggregated);
        }
    }

    /**
     * Извлечь числовое значение из AnalyticsReportValue.
     */
    private function extractNumericValue(AnalyticsReportValue $value): ?float
    {
        if ($value->getValueNumber() !== null) {
            return (float) $value->getValueNumber();
        }
        if ($value->getValueBool() !== null) {
            return $value->getValueBool() ? 1.0 : 0.0;
        }
        return null;
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

        // Сначала очищаем все агрегаты
        $this->em->getConnection()->executeStatement('TRUNCATE analytics_aggregated_data RESTART IDENTITY');

        $count = 0;
        foreach ($approvedReports as $report) {
            $this->recalculateForReport($report);
            $count++;
        }

        return $count;
    }
}
