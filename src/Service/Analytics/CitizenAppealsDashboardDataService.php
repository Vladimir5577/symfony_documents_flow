<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Repository\Organization\OrganizationRepository;
use Doctrine\DBAL\Connection;

/**
 * Данные для дашборда «Обращение граждан».
 * Отдаёт сырые недельные значения по 7 городам, без агрегаций/KPI —
 * фронт сам считает что ему нужно.
 *
 * Источник — analytics_report_values (только утверждённые отчёты).
 */
final class CitizenAppealsDashboardDataService
{
    /** city slug => human label. Порядок зафиксирован для UI. */
    private const CITY_LABELS = [
        'gorlovka' => 'Горловка г.о.',
        'donetsk' => 'Донецк г.о.',
        'enakievo' => 'Енакиево г.о.',
        'makeevka' => 'Макеевка г.о.',
        'shakhtersk' => 'Шахтёрск г.о.',
        'mariupol' => 'Мариуполь г.о.',
        'yasinovataya' => 'Ясиноватая',
    ];

    private const PREFIX_CALLS = 'calls_';
    private const PREFIX_APPEALS = 'appeals_';

    public function __construct(
        private readonly Connection $connection,
        private readonly OrganizationRepository $organizationRepo,
    ) {
    }

    /**
     * @return array{cities: array, weeks: array}
     */
    public function getData(int $organizationId): array
    {
        $orgIds = $this->resolveOrganizationIds($organizationId);

        $cities = [];
        foreach (self::CITY_LABELS as $key => $name) {
            $cities[] = ['key' => $key, 'name' => $name];
        }

        if (empty($orgIds)) {
            return ['cities' => $cities, 'weeks' => []];
        }

        $keys = $this->allBusinessKeys();
        $rows = $this->fetchReportValues($orgIds, $keys);

        return [
            'cities' => $cities,
            'weeks' => $this->groupByWeek($rows),
        ];
    }

    /**
     * @param int[]    $organizationIds
     * @param string[] $businessKeys
     *
     * @return array<int, array{iso_year: int, iso_week: int, start_date: string, end_date: string, business_key: string, value: string|null}>
     */
    private function fetchReportValues(array $organizationIds, array $businessKeys): array
    {
        // Заглушка: выборка временно отключена, будет переписана вместе с дашборд-слоем.
        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function groupByWeek(array $rows): array
    {
        $weeks = [];

        foreach ($rows as $row) {
            $year = (int) $row['iso_year'];
            $week = (int) $row['iso_week'];
            $weekKey = sprintf('%04d-%02d', $year, $week);

            if (!isset($weeks[$weekKey])) {
                $weeks[$weekKey] = [
                    'year' => $year,
                    'week' => $week,
                    'startDate' => (string) $row['start_date'],
                    'endDate' => (string) $row['end_date'],
                    'label' => self::weekRangeLabel((string) $row['start_date'], (string) $row['end_date']),
                    'cities' => $this->emptyCityValues(),
                ];
            }

            $bk = (string) $row['business_key'];
            $value = (int) round((float) ($row['value'] ?? 0));

            if (str_starts_with($bk, self::PREFIX_CALLS)) {
                $city = substr($bk, strlen(self::PREFIX_CALLS));
                if (isset($weeks[$weekKey]['cities'][$city])) {
                    $weeks[$weekKey]['cities'][$city]['calls'] = $value;
                }
            } elseif (str_starts_with($bk, self::PREFIX_APPEALS)) {
                $city = substr($bk, strlen(self::PREFIX_APPEALS));
                if (isset($weeks[$weekKey]['cities'][$city])) {
                    $weeks[$weekKey]['cities'][$city]['appeals'] = $value;
                }
            }
        }

        ksort($weeks);

        return array_values($weeks);
    }

    /**
     * @return array<string, array{calls: int, appeals: int}>
     */
    private function emptyCityValues(): array
    {
        $values = [];
        foreach (array_keys(self::CITY_LABELS) as $city) {
            $values[$city] = ['calls' => 0, 'appeals' => 0];
        }

        return $values;
    }

    /**
     * @return string[]
     */
    private function allBusinessKeys(): array
    {
        $keys = [];
        foreach (array_keys(self::CITY_LABELS) as $city) {
            $keys[] = self::PREFIX_CALLS . $city;
            $keys[] = self::PREFIX_APPEALS . $city;
        }

        return $keys;
    }

    /**
     * @return int[]
     */
    private function resolveOrganizationIds(int $orgId): array
    {
        if ($orgId === 0) {
            $parents = $this->organizationRepo->findAllParentOrganizations();
            $ids = [];
            foreach ($parents as $parent) {
                $ids = array_merge(
                    $ids,
                    $this->organizationRepo->findOrganizationWithChildrenIds($parent->getId())
                );
            }

            return array_values(array_unique($ids));
        }

        return $this->organizationRepo->findOrganizationWithChildrenIds($orgId);
    }

    private static function weekRangeLabel(string $startDate, string $endDate): string
    {
        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);

        return $start->format('d.m') . '–' . $end->format('d.m');
    }
}
