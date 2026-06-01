<?php

namespace App\Repository\Analytics;

use App\Entity\Analytics\AnalyticsReportValue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsReportValue>
 */
class AnalyticsReportValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsReportValue::class);
    }

    /**
     * Тянет значения подтверждённых отчётов по метрикам указанной категории
     * для списка организаций. Без агрегации — каждая строка отчёта возвращается
     * как есть, с привязкой к периоду.
     *
     * @param int[]  $organizationIds
     *
     * @return list<array{business_key:string, yr:int, mo:int, wk:int, period_end:string, value:string|null}>
     */
    public function findValuesByCategory(array $organizationIds, string $category): array
    {
        if ($organizationIds === []) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        $placeholders = implode(',', array_fill(0, count($organizationIds), '?'));

        $sql = <<<SQL
            SELECT
                m.business_key                              AS business_key,
                EXTRACT(ISOYEAR FROM p.end_date)::int       AS yr,
                EXTRACT(MONTH   FROM p.end_date)::int       AS mo,
                EXTRACT(WEEK    FROM p.end_date)::int       AS wk,
                p.end_date                                  AS period_end,
                rv.value_number                             AS value
            FROM analytics_report_values rv
            JOIN analytics_reports r                  ON r.id = rv.report_id
            JOIN analytics_board_version_metrics bvm  ON bvm.id = rv.board_version_metric_id
            JOIN analytics_metrics m                  ON m.id = bvm.metric_id
            JOIN analytics_periods p                  ON p.id = r.period_id
            WHERE m.category = ?
              AND r.status = 'confirmed'
              AND r.organization_id IN ($placeholders)
            ORDER BY p.end_date ASC
        SQL;

        $params = array_merge(
            [$category],
            array_map('intval', $organizationIds),
        );

        return $conn->executeQuery($sql, $params)->fetchAllAssociative();
    }

    /**
     * Тянет полное дерево метрик из versions всех confirmed-отчётов
     * (вместе со значениями, если значение есть) — для построения иерархического
     * ответа SPA. Возвращает плоский список; группировка/дерево строится в сервисе.
     *
     * @param int[] $organizationIds
     *
     * @return list<array{
     *     report_id:int,
     *     start_date:string,
     *     end_date:string,
     *     vm_id:int,
     *     parent_vm_id:int|null,
     *     position:int,
     *     business_key:string,
     *     name:string,
     *     unit:string|null,
     *     value_number:string|null,
     *     value_json:string|null
     * }>
     */
    public function findReportsWithMetricTree(
        array $organizationIds,
        string $category,
        ?string $from = null,
        ?string $to = null,
        ?int $limit = null,
        int $offset = 0,
    ): array {
        if ($organizationIds === []) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        $orgPlaceholders = implode(',', array_fill(0, count($organizationIds), '?'));

        // Подзапрос отбирает period_id, попадающие в диапазон + пагинация по неделям
        // (LIMIT/OFFSET применяются к неделям, а не к строкам метрик).
        // Сортировка DESC — чтобы limit брал самые свежие недели.
        $subWhere = "r2.status = 'confirmed' AND r2.organization_id IN ($orgPlaceholders)";
        $subParams = array_map('intval', $organizationIds);

        if ($from !== null) {
            $subWhere .= ' AND p2.start_date >= ?';
            $subParams[] = $from;
        }
        if ($to !== null) {
            $subWhere .= ' AND p2.end_date <= ?';
            $subParams[] = $to;
        }

        $subLimit = '';
        if ($limit !== null) {
            $subLimit = ' LIMIT ? OFFSET ?';
            $subParams[] = $limit;
            $subParams[] = $offset;
        }

        $sql = <<<SQL
            SELECT
                r.id            AS report_id,
                p.start_date    AS start_date,
                p.end_date      AS end_date,
                bvm.id          AS vm_id,
                bvm.parent_id   AS parent_vm_id,
                bvm.position    AS position,
                m.business_key  AS business_key,
                m.name          AS name,
                m.unit          AS unit,
                rv.value_number AS value_number,
                rv.value_json   AS value_json
            FROM analytics_reports r
            JOIN analytics_periods p                   ON p.id = r.period_id
            JOIN analytics_board_version_metrics bvm   ON bvm.board_version_id = r.board_version_id
            JOIN analytics_metrics m                   ON m.id = bvm.metric_id
            LEFT JOIN analytics_report_values rv
                   ON rv.report_id = r.id
                  AND rv.board_version_metric_id = bvm.id
            WHERE m.category = ?
              AND r.status = 'confirmed'
              AND r.organization_id IN ($orgPlaceholders)
              AND p.id IN (
                  SELECT p2.id
                  FROM analytics_reports r2
                  JOIN analytics_periods p2 ON p2.id = r2.period_id
                  WHERE $subWhere
                  GROUP BY p2.id, p2.start_date
                  ORDER BY p2.start_date DESC$subLimit
              )
            ORDER BY p.start_date ASC, bvm.position ASC
        SQL;

        $params = array_merge(
            [$category],
            array_map('intval', $organizationIds),
            $subParams,
        );

        return $conn->executeQuery($sql, $params)->fetchAllAssociative();
    }
}
