<?php

namespace App\Repository\Analytics;

use App\Entity\Analytics\AnalyticsReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsReport>
 */
class AnalyticsReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsReport::class);
    }

    /**
     * Плоский список подтверждённых отчётов доски выбранной категории, без метрик.
     * Доска находится по analytics_boards.category (по договорённости — одна доска
     * на категорию). Фильтры by org_ids + диапазон периодов, пагинация page/perPage.
     *
     * @param int[] $organizationIds
     *
     * @return array{
     *     items: list<array{
     *         id:int,
     *         boardId:int,
     *         boardVersionId:int,
     *         organization:array{id:int,name:string|null},
     *         period:array{startDate:string,endDate:string},
     *         status:string,
     *         createdAt:string,
     *         updatedAt:string
     *     }>,
     *     total: int
     * }
     */
    public function findConfirmedListByCategory(
        string $category,
        array $organizationIds,
        ?string $from,
        ?string $to,
        int $page,
        int $perPage,
    ): array {
        if ($organizationIds === []) {
            return ['items' => [], 'total' => 0];
        }

        $conn = $this->getEntityManager()->getConnection();
        $orgPlaceholders = implode(',', array_fill(0, count($organizationIds), '?'));

        $where = "b.category = ?
                  AND r.status = 'confirmed'
                  AND r.organization_id IN ($orgPlaceholders)";
        $params = array_merge([$category], array_map('intval', $organizationIds));

        if ($from !== null) {
            $where .= ' AND p.start_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where .= ' AND p.end_date <= ?';
            $params[] = $to;
        }

        $countSql = <<<SQL
            SELECT COUNT(*) AS cnt
            FROM analytics_reports r
            JOIN analytics_boards  b ON b.id = r.board_id
            JOIN analytics_periods p ON p.id = r.period_id
            WHERE $where
        SQL;

        $total = (int) $conn->executeQuery($countSql, $params)->fetchOne();
        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $offset = ($page - 1) * $perPage;

        $listSql = <<<SQL
            SELECT
                r.id                AS id,
                r.board_id          AS board_id,
                r.board_version_id  AS board_version_id,
                r.organization_id   AS organization_id,
                o.short_name        AS organization_name,
                p.start_date        AS start_date,
                p.end_date          AS end_date,
                r.status            AS status,
                r.created_at        AS created_at,
                r.updated_at        AS updated_at
            FROM analytics_reports r
            JOIN analytics_boards  b ON b.id = r.board_id
            JOIN analytics_periods p ON p.id = r.period_id
            JOIN organization      o ON o.id = r.organization_id
            WHERE $where
            ORDER BY p.start_date DESC, r.id DESC
            LIMIT ? OFFSET ?
        SQL;

        $listParams = array_merge($params, [$perPage, $offset]);
        $rows = $conn->executeQuery($listSql, $listParams)->fetchAllAssociative();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id'             => (int) $row['id'],
                'boardId'        => (int) $row['board_id'],
                'boardVersionId' => (int) $row['board_version_id'],
                'organization'   => [
                    'id'   => (int) $row['organization_id'],
                    'name' => $row['organization_name'] !== null
                        ? (string) $row['organization_name']
                        : null,
                ],
                'period'    => [
                    'startDate' => (string) $row['start_date'],
                    'endDate'   => (string) $row['end_date'],
                ],
                'status'    => (string) $row['status'],
                'createdAt' => (string) $row['created_at'],
                'updatedAt' => (string) $row['updated_at'],
            ];
        }

        return ['items' => $items, 'total' => $total];
    }
}
