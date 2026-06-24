<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Repository\Analytics\AnalyticsReportRepository;
use App\Repository\Analytics\AnalyticsReportValueRepository;
use App\Repository\Organization\OrganizationRepository;

/**
 * Формирует данные отчётов «Абонентский отдел» в виде:
 *   { availableWeeks: [{ startDate, endDate }], weeks: [{ startDate, endDate, reports: [...] }] }
 *
 * Структура идентична CitizenAppealsReportTreeService — отличается только категория метрик
 * (analytics_metrics.category = 'clients_department'). Иерархия строится по
 * analytics_board_version_metrics.parent_id.
 */
final class ClientsDepartmentReportTreeService
{
    private const CATEGORY = 'clients_department';

    public function __construct(
        private readonly AnalyticsReportValueRepository $reportValueRepo,
        private readonly AnalyticsReportRepository $reportRepo,
        private readonly OrganizationRepository $organizationRepo,
    ) {
    }

    /**
     * Плоский список подтверждённых отчётов «Абонентский отдел» (без метрик).
     * Доска находится по analytics_boards.category = 'clients_department'.
     *
     * @return array{
     *     items: list<array<string, mixed>>,
     *     page: int,
     *     perPage: int,
     *     total: int
     * }
     */
    public function getAllReports(
        int $organizationId,
        ?string $from,
        ?string $to,
        int $page,
        int $perPage,
    ): array {
        $orgIds = $this->resolveOrganizationIds($organizationId);
        if ($orgIds === []) {
            return ['items' => [], 'page' => $page, 'perPage' => $perPage, 'total' => 0];
        }

        $result = $this->reportRepo->findConfirmedListByCategory(
            self::CATEGORY,
            $orgIds,
            $from,
            $to,
            $page,
            $perPage,
        );

        return [
            'items'   => $result['items'],
            'page'    => $page,
            'perPage' => $perPage,
            'total'   => $result['total'],
        ];
    }

    /**
     * @return array{
     *     availableWeeks: list<array{startDate: string, endDate: string}>,
     *     weeks: list<array{startDate: string, endDate: string, reports: list<array<string, mixed>>}>
     * }
     */
    public function buildWeeks(
        int $organizationId,
        ?string $from = null,
        ?string $to = null,
        ?int $limit = null,
        int $offset = 0,
    ): array {
        $orgIds = $this->resolveOrganizationIds($organizationId);
        if ($orgIds === []) {
            return ['availableWeeks' => [], 'weeks' => []];
        }

        $availableWeeks = $this->reportRepo->findAvailableWeeksByCategory(
            self::CATEGORY,
            $orgIds,
            $from,
            $to,
        );

        $rows = $this->reportValueRepo->findReportsWithMetricTree(
            $orgIds,
            self::CATEGORY,
            $from,
            $to,
            $limit,
            $offset,
        );
        if ($rows === []) {
            return ['availableWeeks' => $availableWeeks, 'weeks' => []];
        }

        $grouped = [];
        foreach ($rows as $row) {
            $key = $row['start_date'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'startDate' => $row['start_date'],
                    'endDate'   => $row['end_date'],
                    'rows'      => [],
                ];
            }
            $grouped[$key]['rows'][] = $row;
        }

        $weeks = [];
        foreach ($grouped as $period) {
            $weeks[] = [
                'startDate' => $period['startDate'],
                'endDate'   => $period['endDate'],
                'reports'   => $this->buildTree($period['rows']),
            ];
        }

        return ['availableWeeks' => $availableWeeks, 'weeks' => $weeks];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function buildTree(array $rows): array
    {
        /** @var array<int, array<string, mixed>> $nodes */
        $nodes = [];
        /** @var array<int, list<int>> $childrenByParent */
        $childrenByParent = [];

        foreach ($rows as $row) {
            $vmId = (int) $row['vm_id'];

            $valueJson = $row['value_json'];
            if (is_string($valueJson) && $valueJson !== '') {
                $decoded = json_decode($valueJson, true);
                $valueJson = $decoded !== null ? $decoded : null;
            }

            $nodes[$vmId] = [
                'metric_key'  => $row['business_key'],
                'name'        => $row['name'],
                'unit'        => $row['unit'],
                'valueNumber' => $row['value_number'] !== null ? (float) $row['value_number'] : null,
                'valueJSON'   => $valueJson ?: null,
            ];

            $parentKey = $row['parent_vm_id'] !== null ? (int) $row['parent_vm_id'] : 0;
            $childrenByParent[$parentKey][] = $vmId;
        }

        $build = function (int $parentKey) use (&$build, &$nodes, &$childrenByParent): array {
            $result = [];
            foreach ($childrenByParent[$parentKey] ?? [] as $vmId) {
                $node = $nodes[$vmId];
                $node['children'] = $build($vmId);
                $result[] = $node;
            }
            return $result;
        };

        return $build(0);
    }

    /**
     * @return int[]
     */
    private function resolveOrganizationIds(int $organizationId): array
    {
        if ($organizationId > 0) {
            return $this->organizationRepo->findOrganizationWithChildrenIds($organizationId);
        }

        $parents = $this->organizationRepo->findAllParentOrganizations();
        $ids = [];
        foreach ($parents as $parent) {
            $ids = array_merge(
                $ids,
                $this->organizationRepo->findOrganizationWithChildrenIds($parent->getId()),
            );
        }

        return array_values(array_unique($ids));
    }
}
