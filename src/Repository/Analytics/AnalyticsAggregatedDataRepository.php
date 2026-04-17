<?php

namespace App\Repository\Analytics;

use App\Entity\Analytics\AnalyticsAggregatedData;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsAggregatedData>
 */
class AnalyticsAggregatedDataRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsAggregatedData::class);
    }

    /**
     * Агрегированные данные по месяцам для заданных организаций и метрик.
     *
     * @param int[]    $organizationIds ID организаций (включая дочерние)
     * @param string[] $businessKeys    Ключи метрик (cash_inflow, tko_export и т.д.)
     *
     * @return array<int, array{business_key: string, yr: int, mo: int, total_value: string|null, avg_value: string|null}>
     */
    public function findMonthlyAggregated(array $organizationIds, array $businessKeys): array
    {
        if (empty($organizationIds) || empty($businessKeys)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<'SQL'
            SELECT a.business_key,
                   EXTRACT(YEAR FROM p.start_date)::int  AS yr,
                   EXTRACT(MONTH FROM p.start_date)::int AS mo,
                   SUM(a.value)  AS total_value,
                   AVG(a.value)  AS avg_value
            FROM analytics_aggregated_data a
            JOIN analytics_periods p ON a.period_id = p.id
            WHERE a.business_key IN (:keys)
              AND a.organization_id IN (:orgIds)
            GROUP BY a.business_key, yr, mo
            ORDER BY yr, mo
        SQL;

        // Doctrine DBAL не поддерживает IN с именованными параметрами напрямую,
        // используем expandArrayParameters через executeQuery
        $orgPlaceholders = implode(',', array_fill(0, count($organizationIds), '?'));
        $keyPlaceholders = implode(',', array_fill(0, count($businessKeys), '?'));

        $sql = <<<SQL
            SELECT a.business_key,
                   EXTRACT(YEAR FROM p.start_date)::int  AS yr,
                   EXTRACT(MONTH FROM p.start_date)::int AS mo,
                   SUM(a.value)  AS total_value,
                   AVG(a.value)  AS avg_value
            FROM analytics_aggregated_data a
            JOIN analytics_periods p ON a.period_id = p.id
            WHERE a.business_key IN ({$keyPlaceholders})
              AND a.organization_id IN ({$orgPlaceholders})
            GROUP BY a.business_key, yr, mo
            ORDER BY yr, mo
        SQL;

        $params = array_merge($businessKeys, $organizationIds);

        return $conn->executeQuery($sql, $params)->fetchAllAssociative();
    }

    /**
     * Агрегированные данные по ISO-неделям.
     *
     * @param int[] $organizationIds
     * @param string[] $businessKeys
     *
     * @return array<int, array{business_key: string, yr: int, wk: int, total_value: string|null, avg_value: string|null}>
     */
    public function findWeeklyAggregated(array $organizationIds, array $businessKeys): array
    {
        if (empty($organizationIds) || empty($businessKeys)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        $orgPlaceholders = implode(',', array_fill(0, count($organizationIds), '?'));
        $keyPlaceholders = implode(',', array_fill(0, count($businessKeys), '?'));

        $sql = <<<SQL
            SELECT a.business_key,
                   p.iso_year AS yr,
                   p.iso_week AS wk,
                   SUM(a.value) AS total_value,
                   AVG(a.value) AS avg_value
            FROM analytics_aggregated_data a
            JOIN analytics_periods p ON a.period_id = p.id
            WHERE a.business_key IN ({$keyPlaceholders})
              AND a.organization_id IN ({$orgPlaceholders})
              AND p.iso_year IS NOT NULL
              AND p.iso_week IS NOT NULL
            GROUP BY a.business_key, p.iso_year, p.iso_week
            ORDER BY p.iso_year, p.iso_week
        SQL;

        $params = array_merge($businessKeys, $organizationIds);

        return $conn->executeQuery($sql, $params)->fetchAllAssociative();
    }

    /**
     * Данные за конкретный месяц с разбивкой по организациям.
     *
     * @param int[]    $organizationIds
     * @param string[] $businessKeys
     *
     * @return array<int, array{organization_id: int, business_key: string, total_value: string|null, avg_value: string|null}>
     */
    public function findCompareMonthly(array $organizationIds, array $businessKeys, int $year, int $month): array
    {
        if (empty($organizationIds) || empty($businessKeys)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        $orgPlaceholders = implode(',', array_fill(0, count($organizationIds), '?'));
        $keyPlaceholders = implode(',', array_fill(0, count($businessKeys), '?'));

        $sql = <<<SQL
            SELECT a.organization_id,
                   a.business_key,
                   SUM(a.value)  AS total_value,
                   AVG(a.value)  AS avg_value
            FROM analytics_aggregated_data a
            JOIN analytics_periods p ON a.period_id = p.id
            WHERE a.business_key IN ({$keyPlaceholders})
              AND a.organization_id IN ({$orgPlaceholders})
              AND EXTRACT(YEAR FROM p.start_date)::int = ?
              AND EXTRACT(MONTH FROM p.start_date)::int = ?
            GROUP BY a.organization_id, a.business_key
            ORDER BY a.organization_id, a.business_key
        SQL;

        $params = array_merge($businessKeys, $organizationIds, [$year, $month]);

        return $conn->executeQuery($sql, $params)->fetchAllAssociative();
    }

    /**
     * Данные за конкретную ISO-неделю с разбивкой по организациям.
     *
     * @param int[]    $organizationIds
     * @param string[] $businessKeys
     *
     * @return array<int, array{organization_id: int, business_key: string, total_value: string|null, avg_value: string|null}>
     */
    public function findCompareWeekly(array $organizationIds, array $businessKeys, int $year, int $week): array
    {
        if (empty($organizationIds) || empty($businessKeys)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        $orgPlaceholders = implode(',', array_fill(0, count($organizationIds), '?'));
        $keyPlaceholders = implode(',', array_fill(0, count($businessKeys), '?'));

        $sql = <<<SQL
            SELECT a.organization_id,
                   a.business_key,
                   SUM(a.value)  AS total_value,
                   AVG(a.value)  AS avg_value
            FROM analytics_aggregated_data a
            JOIN analytics_periods p ON a.period_id = p.id
            WHERE a.business_key IN ({$keyPlaceholders})
              AND a.organization_id IN ({$orgPlaceholders})
              AND p.iso_year = ?
              AND p.iso_week = ?
              AND p.iso_year IS NOT NULL
              AND p.iso_week IS NOT NULL
            GROUP BY a.organization_id, a.business_key
            ORDER BY a.organization_id, a.business_key
        SQL;

        $params = array_merge($businessKeys, $organizationIds, [$year, $week]);

        return $conn->executeQuery($sql, $params)->fetchAllAssociative();
    }

    /**
     * Список доступных месяцев (год/месяц) для заданных организаций.
     *
     * @param int[] $organizationIds
     * @return array<int, array{yr: int, mo: int}>
     */
    public function findAvailableMonths(array $organizationIds): array
    {
        if (empty($organizationIds)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        $orgPlaceholders = implode(',', array_fill(0, count($organizationIds), '?'));

        $sql = <<<SQL
            SELECT DISTINCT
                   EXTRACT(YEAR FROM p.start_date)::int  AS yr,
                   EXTRACT(MONTH FROM p.start_date)::int AS mo
            FROM analytics_aggregated_data a
            JOIN analytics_periods p ON a.period_id = p.id
            WHERE a.organization_id IN ({$orgPlaceholders})
            ORDER BY yr DESC, mo DESC
        SQL;

        return $conn->executeQuery($sql, $organizationIds)->fetchAllAssociative();
    }

    /**
     * Список доступных ISO-недель для заданных организаций.
     *
     * @param int[] $organizationIds
     * @return array<int, array{yr: int, wk: int}>
     */
    public function findAvailableWeeks(array $organizationIds): array
    {
        if (empty($organizationIds)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        $orgPlaceholders = implode(',', array_fill(0, count($organizationIds), '?'));

        $sql = <<<SQL
            SELECT DISTINCT
                   p.iso_year AS yr,
                   p.iso_week AS wk
            FROM analytics_aggregated_data a
            JOIN analytics_periods p ON a.period_id = p.id
            WHERE a.organization_id IN ({$orgPlaceholders})
              AND p.iso_year IS NOT NULL
              AND p.iso_week IS NOT NULL
            ORDER BY yr DESC, wk DESC
        SQL;

        return $conn->executeQuery($sql, $organizationIds)->fetchAllAssociative();
    }
}
