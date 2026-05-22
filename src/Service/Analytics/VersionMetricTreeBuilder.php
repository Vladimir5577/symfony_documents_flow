<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsMetric;

final class VersionMetricTreeBuilder
{
    /**
     * Возвращает плоский список entries в порядке обхода дерева (родитель → дети)
     * с добавленным полем `depth` (0 для корней).
     *
     * @param list<array{metric: AnalyticsMetric, data: array{position: int, is_required: bool, parent_metric_id: int|null}}> $entries
     *
     * @return list<array{metric: AnalyticsMetric, data: array{position: int, is_required: bool, parent_metric_id: int|null}, depth: int}>
     */
    public function flatten(array $entries): array
    {
        /** @var array<int, array{metric: AnalyticsMetric, data: array}> $byId */
        $byId = [];
        foreach ($entries as $entry) {
            $id = $entry['metric']->getId();
            if ($id === null) {
                continue;
            }
            $byId[$id] = $entry;
        }

        // Группируем по родителю; 0 = корень (метрики без родителя или с parent вне selected).
        /** @var array<int, list<array{metric: AnalyticsMetric, data: array}>> $childrenByParent */
        $childrenByParent = [];
        foreach ($entries as $entry) {
            $parentId = $entry['data']['parent_metric_id'] ?? null;
            if ($parentId === null || !isset($byId[$parentId])) {
                $parentId = 0;
            }
            $childrenByParent[$parentId][] = $entry;
        }

        foreach ($childrenByParent as &$bucket) {
            usort(
                $bucket,
                static fn (array $a, array $b): int => ((int) ($a['data']['position'] ?? 0)) <=> ((int) ($b['data']['position'] ?? 0)),
            );
        }
        unset($bucket);

        /** @var list<array{metric: AnalyticsMetric, data: array, depth: int}> $result */
        $result = [];
        /** @var array<int, bool> $visited */
        $visited = [];

        $walk = function (int $parentKey, int $depth) use (&$walk, &$result, &$visited, $childrenByParent): void {
            if (!isset($childrenByParent[$parentKey])) {
                return;
            }
            foreach ($childrenByParent[$parentKey] as $entry) {
                $id = $entry['metric']->getId();
                if ($id === null || isset($visited[$id])) {
                    continue;
                }
                $visited[$id] = true;
                $result[] = $entry + ['depth' => $depth];
                $walk($id, $depth + 1);
            }
        };

        $walk(0, 0);

        // Хвосты на случай циклов в данных: добавляем как корни, чтобы ничего не потерять.
        foreach ($entries as $entry) {
            $id = $entry['metric']->getId();
            if ($id !== null && !isset($visited[$id])) {
                $visited[$id] = true;
                $result[] = $entry + ['depth' => 0];
            }
        }

        return $result;
    }
}
