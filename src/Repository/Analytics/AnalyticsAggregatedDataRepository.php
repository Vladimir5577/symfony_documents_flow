<?php

namespace App\Repository\Analytics;

use App\Entity\Analytics\AnalyticsAggregatedData;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsAggregatedData>
 *
 * Заглушка: запросы по агрегатам временно удалены и будут переписаны.
 * Сигнатуры сохранены, чтобы вызовы из дашборд-сервисов не падали.
 */
class AnalyticsAggregatedDataRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsAggregatedData::class);
    }

    /**
     * @param int[]    $organizationIds
     * @param string[] $businessKeys
     * @return array<int, array{business_key: string, yr: int, mo: int, total_value: string|null, avg_value: string|null}>
     */
    public function findMonthlyAggregated(array $organizationIds, array $businessKeys): array
    {
        return [];
    }

    /**
     * @param int[]    $organizationIds
     * @param string[] $businessKeys
     * @return array<int, array{business_key: string, yr: int, wk: int, total_value: string|null, avg_value: string|null}>
     */
    public function findWeeklyAggregated(array $organizationIds, array $businessKeys): array
    {
        return [];
    }

    /**
     * @param int[]    $organizationIds
     * @param string[] $businessKeys
     * @return array<int, array{organization_id: int, business_key: string, total_value: string|null, avg_value: string|null}>
     */
    public function findCompareMonthly(array $organizationIds, array $businessKeys, int $year, int $month): array
    {
        return [];
    }

    /**
     * @param int[]    $organizationIds
     * @param string[] $businessKeys
     * @return array<int, array{organization_id: int, business_key: string, total_value: string|null, avg_value: string|null}>
     */
    public function findCompareWeekly(array $organizationIds, array $businessKeys, int $year, int $week): array
    {
        return [];
    }

    /**
     * @param int[] $organizationIds
     * @return array<int, array{yr: int, mo: int}>
     */
    public function findAvailableMonths(array $organizationIds): array
    {
        return [];
    }

    /**
     * @param int[] $organizationIds
     * @return array<int, array{yr: int, wk: int}>
     */
    public function findAvailableWeeks(array $organizationIds): array
    {
        return [];
    }
}
