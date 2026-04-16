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
}
