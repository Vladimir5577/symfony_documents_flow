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

    private const NOTES_MAX_DEPTH = 5;
    private const NOTES_FIELD_MAX_LEN = 500;

    /**
     * Заполнить/обновить значения метрик в отчёте.
     *
     * @param array<int|string, mixed>          $values Массив [boardVersionMetricId => scalarValue]
     * @param array<int|string, array<int, mixed>> $notes  Массив [boardVersionMetricId => дерево пояснений]
     */
    public function fillValues(
        AnalyticsReport $report,
        array $values,
        User $user,
        bool $forceEdit = false,
        array $notes = [],
    ): void {
        if (!$forceEdit && $report->getStatus()->value !== 'draft') {
            throw new \RuntimeException('Редактировать можно только черновик.');
        }

        /** @var AnalyticsReportValue[] $existingMap */
        $existingMap = [];
        foreach ($report->getValues() as $v) {
            $existingMap[$v->getBoardVersionMetric()->getId()] = $v;
        }

        // Объединяем ключи: метрика может прийти и через values, и через notes
        $metricIds = array_unique(array_merge(
            array_map('intval', array_keys($values)),
            array_map('intval', array_keys($notes)),
        ));

        foreach ($metricIds as $metricId) {
            $versionMetric = null;
            foreach ($report->getBoardVersion()->getVersionMetrics() as $vm) {
                if ($vm->getId() === $metricId) {
                    $versionMetric = $vm;
                    break;
                }
            }
            if (!$versionMetric) {
                continue;
            }

            $metric = $versionMetric->getMetric();
            if (!$metric) {
                continue;
            }

            $value = $values[$metricId] ?? null;
            $isValueEmpty = $value === '' || $value === null;

            $rawNotes = $notes[$metricId] ?? [];
            $sanitizedNotes = is_array($rawNotes) ? $this->sanitizeNotesTree($rawNotes) : [];
            $hasNotes = $sanitizedNotes !== [];

            if ($isValueEmpty && !$hasNotes) {
                if (isset($existingMap[$metricId])) {
                    $this->em->remove($existingMap[$metricId]);
                }
                continue;
            }

            $reportValue = $existingMap[$metricId] ?? new AnalyticsReportValue();
            $reportValue->setCreatedBy($user);

            $reportValue->setValueNumber(null);
            $reportValue->setValueText(null);
            $reportValue->setValueBool(null);

            if (!$isValueEmpty) {
                $type = $metric->getType();
                if ($type === 'bool') {
                    $reportValue->setValueBool((bool) $value);
                } elseif ($type === 'text') {
                    $reportValue->setValueText((string) $value);
                } elseif (in_array($type, ['number', 'currency', 'distance', 'liters', 'count'], true)) {
                    $reportValue->setValueNumber((string) $value);
                } else {
                    $reportValue->setValueText((string) $value);
                }
            }

            $reportValue->setValueJson($hasNotes ? $sanitizedNotes : null);

            if (!$report->getValues()->contains($reportValue)) {
                $reportValue->setReport($report);
                $reportValue->setBoardVersionMetric($versionMetric);
                $report->addValue($reportValue);
            }
        }

        $this->em->flush();
    }

    /**
     * Санитизация дерева пояснений: обрезка глубины, длины строк, отсев пустых нод.
     *
     * @param array<int, mixed> $nodes
     *
     * @return list<array{key: string, value: string, children: list<mixed>}>
     */
    private function sanitizeNotesTree(array $nodes, int $depth = 0): array
    {
        if ($depth >= self::NOTES_MAX_DEPTH) {
            return [];
        }

        $result = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $key = $this->sanitizeNotesField($node['key'] ?? '');
            $value = $this->sanitizeNotesField($node['value'] ?? '');
            $rawChildren = $node['children'] ?? [];
            $children = is_array($rawChildren) ? $this->sanitizeNotesTree($rawChildren, $depth + 1) : [];

            if ($key === '' && $value === '' && $children === []) {
                continue;
            }

            $result[] = [
                'key' => $key,
                'value' => $value,
                'children' => $children,
            ];
        }

        return $result;
    }

    private function sanitizeNotesField(mixed $raw): string
    {
        if (!is_scalar($raw)) {
            return '';
        }
        $str = trim((string) $raw);
        if (mb_strlen($str) > self::NOTES_FIELD_MAX_LEN) {
            $str = mb_substr($str, 0, self::NOTES_FIELD_MAX_LEN);
        }
        return $str;
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
}
