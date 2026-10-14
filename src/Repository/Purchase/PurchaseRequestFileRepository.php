<?php

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseRequestFile;
use App\Enum\Purchase\PurchaseContractStatus;
use App\Enum\Purchase\PurchaseFileType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseRequestFile>
 */
class PurchaseRequestFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseRequestFile::class);
    }

    /**
     * Все договоры. Колонки отделов пустые, пока задача не закрыта.
     *
     * Имя и дата — decided_by_id / decided_at задачи отдела, в чьих
     * contract_reviewed_file_ids есть этот договор. Профзам на задаче без
     * role_code: роль пула лежит на этапе. Несколько подписантов — последний.
     *
     * @return array{
     *     items: list<array{
     *         id: int,
     *         name: string,
     *         fileName: string,
     *         purchaseId: int,
     *         uploadedBy: array{id: int, name: string}|null,
     *         createdAt: string|null,
     *         confirmed: bool,
     *         approvedByLegal: array{name: string, decidedAt: string|null}|null,
     *         approvedByAccounting: array{name: string, decidedAt: string|null}|null,
     *         approvedByDeputy: array{name: string, decidedAt: string|null}|null,
     *         signedContracts: list<array{id: int, name: string, purchaseId: int}>,
     *         relatedRequestCount: int,
     *         relatedFileCount: int
     *     }>,
     *     total: int
     * }
     */
    public function findForList(
        string $search,
        bool $confirmedOnly,
        int $page,
        int $pageSize,
        ?int $excludePurchaseId = null,
    ): array
    {
        // ponytail: jsonb @> по странице; expression index, если список станет тяжёлым
        $legal = $this->approverJoin('legal', "t.role_code = 'LEGAL'");
        $accounting = $this->approverJoin('accounting', "t.role_code = 'ACCOUNTING'");
        $deputy = $this->approverJoin('deputy', "(t.role_code = 'PROFILE_DEPUTY' OR s.candidate_role_code = 'PROFILE_DEPUTY')");
        // Неподтверждённый договор виден только на своей заявке, не в справочнике.
        $conditions = ["c.status <> 'DRAFT'"];
        $params = [
            'limit' => $pageSize,
            'offset' => max(0, ($page - 1) * $pageSize),
        ];
        $types = [
            'limit' => ParameterType::INTEGER,
            'offset' => ParameterType::INTEGER,
        ];
        if ($search !== '') {
            $conditions[] = "(c.name ILIKE :search ESCAPE '\\' OR f.original_name ILIKE :search ESCAPE '\\' OR CONCAT(COALESCE(u.lastname, ''), ' ', COALESCE(u.firstname, '')) ILIKE :search ESCAPE '\\' OR COALESCE(u.login, '') ILIKE :search ESCAPE '\\')";
            $params['search'] = '%'.addcslashes($search, '%_\\').'%';
        }
        if ($confirmedOnly) {
            $conditions[] = "c.status = 'ACTIVE'";
        }
        if ($excludePurchaseId !== null) {
            $conditions[] = 'NOT EXISTS (
                SELECT 1
                FROM purchase_request_contract xc
                WHERE xc.purchase_contract_id = c.id
                  AND xc.purchase_request_id = :exclude_purchase
            )';
            $params['exclude_purchase'] = $excludePurchaseId;
            $types['exclude_purchase'] = ParameterType::INTEGER;
        }
        $where = implode(' AND ', $conditions);
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(<<<SQL
            SELECT
                c.id,
                c.status,
                c.name AS contract_name,
                f.id AS file_id,
                f.original_name,
                f.purchase_request_id,
                COALESCE(f.created_at, origin.created_at) AS created_at,
                u.id AS uploader_id,
                u.lastname,
                u.firstname,
                u.login,
                legal.name AS legal_name,
                legal.decided_at AS legal_decided_at,
                accounting.name AS accounting_name,
                accounting.decided_at AS accounting_decided_at,
                deputy.name AS deputy_name,
                deputy.decided_at AS deputy_decided_at,
                signed.contracts AS signed_contracts,
                (
                    SELECT COUNT(*)
                    FROM purchase_request_contract rc
                    WHERE rc.purchase_contract_id = c.id
                ) AS related_count,
                (
                    SELECT COUNT(*)
                    FROM purchase_request_file rf
                    WHERE rf.purchase_contract_id = c.id
                ) AS related_file_count,
                COUNT(*) OVER() AS total
            FROM purchase_contract c
            LEFT JOIN LATERAL (
                SELECT id, original_name, purchase_request_id, created_at, uploaded_by_id
                FROM purchase_request_file
                WHERE purchase_contract_id = c.id
                  AND type = 'CONTRACT'
                ORDER BY id
                LIMIT 1
            ) f ON true
            LEFT JOIN LATERAL (
                SELECT uploaded_by_id, created_at
                FROM purchase_request_file
                WHERE purchase_contract_id = c.id
                ORDER BY CASE WHEN type = 'CONTRACT' THEN 0 ELSE 1 END, id
                LIMIT 1
            ) origin ON true
            LEFT JOIN "user" u ON u.id = COALESCE(f.uploaded_by_id, origin.uploaded_by_id)
            {$legal}
            {$accounting}
            {$deputy}
            LEFT JOIN LATERAL (
                SELECT COALESCE(
                    json_agg(
                        json_build_object('id', sf.id, 'name', sf.original_name, 'purchaseId', sf.purchase_request_id)
                        ORDER BY sf.created_at, sf.id
                    ),
                    '[]'::json
                ) AS contracts
                FROM purchase_request_file sf
                WHERE sf.purchase_contract_id = c.id
                  AND sf.type = 'SIGNED_CONTRACT'
            ) signed ON true
            WHERE {$where}
            ORDER BY c.created_at DESC, c.id DESC
            LIMIT :limit OFFSET :offset
            SQL, $params, $types);

        return [
            'items' => array_map($this->presentApprovedContract(...), $rows),
            'total' => $rows === [] ? 0 : (int) $rows[0]['total'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     fileName: string,
     *     purchaseId: int,
     *     uploadedBy: array{id: int, name: string}|null,
     *     createdAt: string|null,
     *     confirmed: bool,
     *     approvedByLegal: array{name: string, decidedAt: string|null}|null,
     *     approvedByAccounting: array{name: string, decidedAt: string|null}|null,
     *     approvedByDeputy: array{name: string, decidedAt: string|null}|null,
     *     signedContracts: list<array{id: int, name: string, purchaseId: int}>,
     *     relatedRequestCount: int,
     *     relatedFileCount: int
     * }
     */
    private function presentApprovedContract(array $row): array
    {
        $uploaderId = $row['uploader_id'] ?? null;
        $name = trim(((string) ($row['lastname'] ?? '')).' '.((string) ($row['firstname'] ?? '')));
        if ($name === '') {
            $name = (string) ($row['login'] ?? '');
        }

        $status = PurchaseContractStatus::tryFrom((string) ($row['status'] ?? ''));

        return [
            'id' => (int) $row['id'],
            'fileId' => $row['file_id'] !== null ? (int) $row['file_id'] : null,
            'name' => (string) ($row['contract_name'] ?? ''),
            'fileName' => (string) ($row['original_name'] ?? ''),
            'purchaseId' => $row['purchase_request_id'] !== null ? (int) $row['purchase_request_id'] : null,
            'uploadedBy' => $uploaderId === null ? null : [
                'id' => (int) $uploaderId,
                'name' => $name !== '' ? $name : '—',
            ],
            'createdAt' => $this->createdAt($row['created_at'] ?? null),
            'status' => [
                'value' => $status?->value ?? (string) ($row['status'] ?? ''),
                'label' => $status?->getLabel() ?? (string) ($row['status'] ?? ''),
            ],
            'confirmed' => $status === PurchaseContractStatus::ACTIVE,
            'approvedByLegal' => $this->approver($row, 'legal'),
            'approvedByAccounting' => $this->approver($row, 'accounting'),
            'approvedByDeputy' => $this->approver($row, 'deputy'),
            'signedContracts' => $this->signedContracts($row['signed_contracts'] ?? '[]'),
            'relatedRequestCount' => (int) ($row['related_count'] ?? 0),
            'relatedFileCount' => (int) ($row['related_file_count'] ?? 0),
        ];
    }

    /**
     * Задача отдела, отметившая этот договор. Имя — decided_by, дата — decided_at.
     * ponytail: один подписант (последний decided_at); string_agg, если замов несколько и нужны все.
     */
    private function approverJoin(string $alias, string $match): string
    {
        return <<<SQL
            LEFT JOIN LATERAL (
                SELECT
                    COALESCE(NULLIF(TRIM(CONCAT(du.lastname, ' ', du.firstname)), ''), du.login) AS name,
                    t.decided_at
                FROM purchase_approval_task t
                INNER JOIN purchase_approval_stage s ON s.id = t.stage_id
                INNER JOIN "user" du ON du.id = t.decided_by_id
                WHERE s.purchase_request_id = f.purchase_request_id
                  AND {$match}
                  AND t.contract_reviewed_file_ids::jsonb @> jsonb_build_array(f.id)
                ORDER BY t.decided_at DESC NULLS LAST
                LIMIT 1
            ) {$alias} ON true
            SQL;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{name: string, decidedAt: string|null}|null
     */
    private function approver(array $row, string $alias): ?array
    {
        $name = trim((string) ($row[$alias.'_name'] ?? ''));
        if ($name === '') {
            return null;
        }

        return [
            'name' => $name,
            'decidedAt' => $this->createdAt($row[$alias.'_decided_at'] ?? null),
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function signedContracts(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            return [];
        }

        $files = [];
        foreach ($value as $file) {
            if (!is_array($file) || !isset($file['id'])) {
                continue;
            }
            $files[] = [
                'id' => (int) $file['id'],
                'name' => (string) ($file['name'] ?? ''),
                'purchaseId' => isset($file['purchaseId']) && $file['purchaseId'] !== null
                    ? (int) $file['purchaseId']
                    : null,
            ];
        }

        return $files;
    }

    /**
     * Заявки, к которым прикреплён договор, и позиции, идущие по его файлу.
     *
     * @return list<array{id: int, title: string, total: float, items: list<array{name: string, quantity: float, amount: float}>}>
     */
    public function findRequests(int $contractId): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT pr.id, pr.title
            FROM purchase_request_contract rc
            INNER JOIN purchase_request pr ON pr.id = rc.purchase_request_id
            WHERE rc.purchase_contract_id = :id
            ORDER BY pr.id',
            ['id' => $contractId],
            ['id' => ParameterType::INTEGER],
        );

        $itemRows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT i.purchase_request_id, i.name, i.quantity, i.approved_quantity, i.estimated_price, i.in_stock
            FROM purchase_request_item i
            WHERE i.contract_id = :id AND i.excluded = false
            ORDER BY i.purchase_request_id, i.position',
            ['id' => $contractId],
            ['id' => ParameterType::INTEGER],
        );

        $byRequest = [];
        foreach ($itemRows as $item) {
            $requestId = (int) $item['purchase_request_id'];
            $quantity = (float) ($item['approved_quantity'] ?? $item['quantity']);
            $inStock = $item['in_stock'] === true || $item['in_stock'] === 't' || $item['in_stock'] === '1' || $item['in_stock'] === 1;
            $amount = $inStock ? 0.0 : self::lineAmount((string) $item['quantity'], $item['approved_quantity'], (string) $item['estimated_price']);
            $byRequest[$requestId][] = [
                'name' => (string) ($item['name'] ?? ''),
                'quantity' => $quantity,
                'amount' => $amount,
            ];
        }

        return array_map(static function (array $row) use ($byRequest): array {
            $id = (int) $row['id'];
            $items = $byRequest[$id] ?? [];

            return [
                'id' => $id,
                'title' => (string) ($row['title'] ?? ''),
                'items' => $items,
                'total' => array_sum(array_column($items, 'amount')),
            ];
        }, $rows);
    }

    /** Сумма строки в рублях, как PurchaseRequest::getTotalAmountKopecks: утверждённое количество, иначе заявленное. */
    private static function lineAmount(string $quantity, mixed $approved, string $price): float
    {
        $qty = $approved !== null && $approved !== '' ? (string) $approved : $quantity;
        $toInt = static function (string $decimal, int $scale): int {
            [$whole, $fraction] = array_pad(explode('.', trim($decimal), 2), 2, '');

            return (int) ($whole . substr(str_pad($fraction, $scale, '0'), 0, $scale));
        };
        $kopecks = $toInt($price, 2);
        $thousandths = $toInt($qty, 3);

        return intdiv($kopecks * $thousandths + 500, 1000) / 100;
    }

    /**
     * Файлы, прикреплённые к договору. Отдельный запрос: в списке их только считают.
     *
     * @return list<array{id: int, name: string, purchaseId: int, type: array{value: string, label: string}}>
     */
    public function findFiles(int $contractId): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT f.id, f.original_name, f.purchase_request_id, f.type
            FROM purchase_request_file f
            WHERE f.purchase_contract_id = :id
            ORDER BY f.id',
            ['id' => $contractId],
            ['id' => ParameterType::INTEGER],
        );

        return array_map(static function (array $row): array {
            $type = PurchaseFileType::tryFrom((string) ($row['type'] ?? ''));

            return [
                'id' => (int) $row['id'],
                'name' => (string) ($row['original_name'] ?? ''),
                'purchaseId' => $row['purchase_request_id'] !== null ? (int) $row['purchase_request_id'] : null,
                'type' => [
                    'value' => $type?->value ?? (string) ($row['type'] ?? ''),
                    'label' => $type?->getLabel() ?? (string) ($row['type'] ?? ''),
                ],
            ];
        }, $rows);
    }

    private function createdAt(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function isConfirmed(int $fileId): bool
    {
        return self::isTrue($this->getEntityManager()->getConnection()->fetchOne(
            "SELECT EXISTS (
                SELECT 1
                FROM purchase_approval_task t
                WHERE t.contract_review = 'ACCEPT'
                  AND t.contract_reviewed_file_ids::jsonb @> jsonb_build_array(CAST(:id AS integer))
            )",
            ['id' => $fileId],
            ['id' => ParameterType::INTEGER],
        ));
    }

    /** pdo_pgsql отдаёт boolean то bool, то 't'/'f'. (bool) 'f' в PHP — true. */
    private static function isTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
