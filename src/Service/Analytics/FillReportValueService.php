<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsReport;
use App\Entity\Analytics\AnalyticsReportValue;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Заполнение значений метрик в отчёте.
 */
final class FillReportValueService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Заполнить/обновить значения метрик в отчёте.
     *
     * @param array<int, mixed> $values Массив [boardVersionMetricId => scalarValue]
     */
    public function fillValues(AnalyticsReport $report, array $values, User $user): void
    {
        if ($report->getStatus()->value !== 'draft') {
            throw new \RuntimeException('Редактировать можно только черновик.');
        }

        // Собираем существующие значения в map для быстрого поиска
        /** @var AnalyticsReportValue[] $existingMap */
        $existingMap = [];
        foreach ($report->getValues() as $v) {
            $existingMap[$v->getBoardVersionMetric()->getId()] = $v;
        }

        foreach ($values as $metricIdStr => $value) {
            $metricId = (int) $metricIdStr;
            $versionMetric = null;
            foreach ($report->getBoardVersion()->getVersionMetrics() as $vm) {
                if ($vm->getId() === $metricId) {
                    $versionMetric = $vm;
                    break;
                }
            }
            if (!$versionMetric) {
                continue; // Метрика не относится к этой версии доски
            }

            $metric = $versionMetric->getMetric();
            if (!$metric) {
                continue;
            }

            // Пропускаем пустые значения (очищаем если было)
            if ($value === '' || $value === null) {
                if (isset($existingMap[$metricId])) {
                    $this->em->remove($existingMap[$metricId]);
                }
                continue;
            }

            $reportValue = $existingMap[$metricId] ?? new AnalyticsReportValue();

            // Snapshot
            $reportValue->setMetricNameSnapshot($metric->getName());
            $reportValue->setMetricUnitSnapshot($metric->getUnit());
            $reportValue->setMetricTypeSnapshot($metric->getType());
            $reportValue->setEffectiveAt(new \DateTimeImmutable());
            $reportValue->setCreatedBy($user);

            // Записываем значение в соответствующее поле
            $type = $metric->getType();
            if ($type === 'bool') {
                $reportValue->setValueBool((bool) $value);
                $reportValue->setValueNumber(null);
                $reportValue->setValueText(null);
                $reportValue->setValueJson(null);
            } elseif ($type === 'text') {
                $reportValue->setValueText((string) $value);
                $reportValue->setValueNumber(null);
                $reportValue->setValueBool(null);
                $reportValue->setValueJson(null);
            } elseif (in_array($type, ['number', 'currency', 'distance', 'liters', 'count'], true)) {
                $reportValue->setValueNumber((string) $value);
                $reportValue->setValueText(null);
                $reportValue->setValueBool(null);
                $reportValue->setValueJson(null);
            } else {
                $reportValue->setValueText((string) $value);
                $reportValue->setValueNumber(null);
                $reportValue->setValueBool(null);
                $reportValue->setValueJson(null);
            }

            // Привязываем к отчёту
            if (!$report->getValues()->contains($reportValue)) {
                $reportValue->setReport($report);
                $reportValue->setBoardVersionMetric($versionMetric);
                $report->addValue($reportValue);
            }
        }

        // Пересчитываем completeness
        $this->recalculateComplete($report);
        $this->em->flush();
    }

    /**
     * Проверить, заполнены ли все обязательные метрики.
     */
    public function checkComplete(AnalyticsReport $report): bool
    {
        $filledMetrics = [];
        foreach ($report->getValues() as $value) {
            $filledMetrics[$value->getBoardVersionMetric()->getId()] = true;
        }

        foreach ($report->getBoardVersion()->getVersionMetrics() as $vm) {
            if ($vm->isRequired() && !isset($filledMetrics[$vm->getId()])) {
                return false;
            }
        }

        return true;
    }

    private function recalculateComplete(AnalyticsReport $report): void
    {
        $report->setIsComplete($this->checkComplete($report));
        $this->em->persist($report);
    }
}
