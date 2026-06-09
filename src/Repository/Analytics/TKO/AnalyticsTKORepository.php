<?php

namespace App\Repository\Analytics\TKO;

use App\Entity\Analytics\TKO\AnalyticsTKO;
use App\Entity\Polygon\Polygon;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsTKO>
 */
class AnalyticsTKORepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsTKO::class);
    }

    /**
     * Записи полигона за диапазон дат (неделю), отсортированные по дате.
     *
     * @return AnalyticsTKO[]
     */
    public function findByPolygonAndDateRange(Polygon $polygon, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.polygon = :polygon')
            ->andWhere('a.reportDate BETWEEN :start AND :end')
            ->setParameter('polygon', $polygon)
            ->setParameter('start', $start, Types::DATE_IMMUTABLE)
            ->setParameter('end', $end, Types::DATE_IMMUTABLE)
            ->orderBy('a.reportDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByPolygonAndDate(Polygon $polygon, \DateTimeImmutable $date): ?AnalyticsTKO
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.polygon = :polygon')
            ->andWhere('a.reportDate = :date')
            ->setParameter('polygon', $polygon)
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Суммарная аналитика по полигону с группировкой по периоду (неделя/месяц).
     * Числовые метрики суммируются (SUM), текстовые — считается число дней с отметкой (COUNT).
     * Агрегация выполняется средствами БД (date_trunc).
     *
     * @param string $unit 'week' | 'month'
     *
     * @return array<string, array<string, string|null>> массив строк, ключ — дата начала бакета (Y-m-d)
     */
    public function aggregateByPolygon(int $polygonId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $unit): array
    {
        $unit = 'month' === $unit ? 'month' : 'week';

        $sql = <<<SQL
            SELECT
                to_char(date_trunc(:unit, report_date), 'YYYY-MM-DD')   AS bucket,
                SUM(garbage_trucks_volume)                              AS garbage_trucks_volume,
                SUM(garbage_trucks_weight)                              AS garbage_trucks_weight,
                SUM(containers_volume)                                  AS containers_volume,
                SUM(scrap_trucks_volume)                               AS scrap_trucks_volume,
                SUM(containers_scrap_weight)                           AS containers_scrap_weight,
                SUM(vegetation_volume)                                  AS vegetation_volume,
                SUM(construction_volume)                                AS construction_volume,
                SUM(terminal_volume)                                    AS terminal_volume,
                COUNT(NULLIF(btrim(machinery_work), ''))                AS machinery_work,
                COUNT(NULLIF(btrim(smoke), ''))                         AS smoke,
                COUNT(NULLIF(btrim(fire_without_mchs), ''))              AS fire_without_mchs,
                COUNT(NULLIF(btrim(fire_with_mchs), ''))                AS fire_with_mchs,
                COUNT(NULLIF(btrim(irrigation), ''))                    AS irrigation
            FROM analytics_tko
            WHERE polygon_id = :pid
              AND report_date BETWEEN :from AND :to
            GROUP BY bucket
            ORDER BY bucket
            SQL;

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, [
            'unit' => $unit,
            'pid' => $polygonId,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]);

        $byBucket = [];
        foreach ($rows as $row) {
            $bucket = $row['bucket'];
            unset($row['bucket']);
            $byBucket[$bucket] = $row;
        }

        return $byBucket;
    }

    /**
     * Список недель (Пн–Вс) от первой до последней даты в analytics_tko.
     *
     * @return list<array{startDate: string, endDate: string}>
     */
    public function findAvailableWeeks(): array
    {
        $connection = $this->getEntityManager()->getConnection();

        $row = $connection->fetchAssociative(
            'SELECT MIN(report_date) AS min_date, MAX(report_date) AS max_date FROM analytics_tko',
        );
        if (false === $row || null === $row['min_date'] || null === $row['max_date']) {
            return [];
        }

        $from = new \DateTimeImmutable($row['min_date'])->modify('monday this week')->setTime(0, 0);
        $to = new \DateTimeImmutable($row['max_date'])->modify('sunday this week')->setTime(0, 0);

        $weeks = [];
        $cursor = $from;
        while ($cursor <= $to) {
            $end = $cursor->modify('+6 days');
            $weeks[] = [
                'startDate' => $cursor->format('Y-m-d'),
                'endDate' => $end->format('Y-m-d'),
            ];
            $cursor = $cursor->modify('+7 days');
        }

        return $weeks;
    }

    /**
     * Недельные агрегаты с разбивкой по полигонам за диапазон дат.
     * Числовые метрики суммируются (SUM), текстовые — считается число дней с отметкой (COUNT).
     *
     * @return list<array<string, mixed>>
     */
    public function aggregateWeeklyByPolygon(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $sql = <<<'SQL'
            SELECT
                to_char(date_trunc('week', report_date::timestamp), 'YYYY-MM-DD') AS week_start,
                polygon_id,
                SUM(garbage_trucks_volume)                              AS garbage_trucks_volume,
                SUM(garbage_trucks_weight)                              AS garbage_trucks_weight,
                SUM(containers_volume)                                  AS containers_volume,
                SUM(scrap_trucks_volume)                                AS scrap_trucks_volume,
                SUM(containers_scrap_weight)                            AS containers_scrap_weight,
                SUM(vegetation_volume)                                  AS vegetation_volume,
                SUM(construction_volume)                                AS construction_volume,
                SUM(terminal_volume)                                    AS terminal_volume,
                COUNT(NULLIF(btrim(machinery_work), ''))                AS machinery_work,
                COUNT(NULLIF(btrim(smoke), ''))                         AS smoke,
                COUNT(NULLIF(btrim(fire_without_mchs), ''))              AS fire_without_mchs,
                COUNT(NULLIF(btrim(fire_with_mchs), ''))                AS fire_with_mchs,
                COUNT(NULLIF(btrim(irrigation), ''))                    AS irrigation
            FROM analytics_tko
            WHERE report_date BETWEEN :from AND :to
            GROUP BY week_start, polygon_id
            ORDER BY week_start, polygon_id
            SQL;

        return $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]);
    }
}
