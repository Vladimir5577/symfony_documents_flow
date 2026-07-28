<?php

namespace App\Repository\Inventory;

use App\Entity\Inventory\Stock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Stock>
 */
class StockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Stock::class);
    }

    public function findByKey(
        int $nomenclatureId,
        ?int $holderUserId,
        ?int $holderWarehouseId,
        ?int $managingWarehouseId,
        ?int $roomId,
    ): ?Stock {
        $qb = $this->createQueryBuilder('s')
            ->where('s.nomenclature = :nomenclatureId')
            ->setParameter('nomenclatureId', $nomenclatureId);

        $this->andWhereNullableId($qb, 's.holderUser', 'holderUserId', $holderUserId);
        $this->andWhereNullableId($qb, 's.holderWarehouse', 'holderWarehouseId', $holderWarehouseId);
        $this->andWhereNullableId($qb, 's.managingWarehouse', 'managingWarehouseId', $managingWarehouseId);
        $this->andWhereNullableId($qb, 's.room', 'roomId', $roomId);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Filters follow the inventory API contract. Grouping is intentionally left to a later contract revision.
     *
     * @param array<string, mixed> $filters
     *
     * @return array<int, Stock>
     */
    public function findFiltered(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.nomenclature', 'n')
            ->addSelect('n')
            ->leftJoin('n.category', 'category')
            ->leftJoin('s.holderUser', 'holderUser')
            ->leftJoin('s.holderWarehouse', 'holderWarehouse')
            ->orderBy('n.name', 'ASC');

        $this->applyFilters($qb, $filters);

        return $qb->getQuery()->getResult();
    }

    /**
     * Детальные остатки страницей. Фильтр, поиск, отсечение нулей и пагинация — в SQL:
     * раньше всё это делалось после выборки, то есть вся таблица остатков приезжала
     * в память ради десяти строк на экране.
     *
     * @param array<string, mixed> $filters
     *
     * @return array{rows: array<int, Stock>, total: int}
     */
    public function findFilteredPaginated(array $filters, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.nomenclature', 'n')
            ->addSelect('n')
            ->leftJoin('n.category', 'category')
            ->addSelect('category')
            ->leftJoin('s.holderUser', 'holderUser')
            ->leftJoin('s.holderWarehouse', 'holderWarehouse')
            ->andWhere('s.quantity > 0')
            ->orderBy('n.name', 'ASC')
            ->addOrderBy('s.id', 'ASC');

        $this->applyFilters($qb, $filters);

        $countQb = clone $qb;
        $total = (int) $countQb
            ->select('COUNT(s.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $rows = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Общие фильтры остатков. Запрос обязан уже иметь алиасы `n`, `holderUser`, `holderWarehouse`.
     *
     * @param array<string, mixed> $filters
     */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        $filterMap = [
            'category_id' => 'n.category',
            'nomenclature_id' => 's.nomenclature',
            'holder_user_id' => 's.holderUser',
            'warehouse_id' => 's.holderWarehouse',
            'managing_warehouse_id' => 's.managingWarehouse',
            'room_id' => 's.room',
        ];

        foreach ($filterMap as $key => $field) {
            if (isset($filters[$key])) {
                $qb->andWhere(sprintf('%s = :%s', $field, $key))
                    ->setParameter($key, (int) $filters[$key]);
            }
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $qb->andWhere('LOWER(n.name) LIKE LOWER(:search)')
                ->setParameter('search', '%' . $search . '%');
        }

        if (isset($filters['department_id'])) {
            // int или список int (скоуп подразделения с потомками — ревью 2026-07-28)
            $departmentIds = array_map('intval', (array) $filters['department_id']);
            $qb->leftJoin('holderUser.organization', 'holderDepartment')
                ->leftJoin('holderWarehouse.department', 'warehouseDepartment')
                ->andWhere('(holderDepartment.id IN (:departmentIds) OR warehouseDepartment.id IN (:departmentIds))')
                ->setParameter('departmentIds', $departmentIds);
        }
    }

    /**
     * User-facing roll-up: SUM(quantity) GROUP BY nomenclature, without managing warehouse and room cuts.
     *
     * @return list<array{nomenclatureId: int|string, nomenclatureName: string, unit: string, quantity: string}>
     */
    public function findByHolderUser(int $userId): array
    {
        return $this->createQueryBuilder('s')
            ->select(
                'IDENTITY(s.nomenclature) AS nomenclatureId',
                'n.name AS nomenclatureName',
                'n.unit AS unit',
                'SUM(s.quantity) AS quantity',
            )
            ->join('s.nomenclature', 'n')
            ->where('s.holderUser = :userId')
            ->setParameter('userId', $userId)
            ->groupBy('s.nomenclature')
            ->addGroupBy('n.name')
            ->addGroupBy('n.unit')
            ->orderBy('n.name', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * @return array<int, Stock>
     */
    public function findPositiveByHolderUser(
        int $nomenclatureId,
        int $holderUserId,
        ?int $roomId = null,
        ?int $managingWarehouseId = null,
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->where('s.nomenclature = :nomenclatureId')
            ->andWhere('s.holderUser = :holderUserId')
            ->andWhere('s.quantity > 0')
            ->setParameter('nomenclatureId', $nomenclatureId)
            ->setParameter('holderUserId', $holderUserId);

        if ($roomId !== null) {
            $qb->andWhere('s.room = :roomId')->setParameter('roomId', $roomId);
        }
        if ($managingWarehouseId !== null) {
            $qb->andWhere('s.managingWarehouse = :managingWarehouseId')
                ->setParameter('managingWarehouseId', $managingWarehouseId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array<int, Stock>
     */
    public function findPositiveByHolderWarehouse(int $nomenclatureId, int $holderWarehouseId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.nomenclature = :nomenclatureId')
            ->andWhere('s.holderWarehouse = :holderWarehouseId')
            ->andWhere('s.quantity > 0')
            ->setParameter('nomenclatureId', $nomenclatureId)
            ->setParameter('holderWarehouseId', $holderWarehouseId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Агрегированные остатки: SUM(quantity) GROUP BY выбранный разрез.
     *
     * Свёртка выполняется СУБД, а не PHP: на выгрузке владельца это 2184 строки,
     * и складывать их в приложении означало бы тянуть весь остаток в память ради
     * одной страницы. Разрезы:
     *  - `nomenclature` — одна строка на позицию, держатель не различается (holder = null);
     *  - `holder` — правило свёртки контракта: (позиция, держатель), без контура и кабинета;
     *  - `room` — (позиция, кабинет), для обхода помещений.
     *
     * Пагинация делается уже по агрегату, в PHP: строк после свёртки на порядки меньше,
     * чем исходных, а посчитать в DQL число ГРУПП без нативного подзапроса нельзя.
     *
     * @param 'nomenclature'|'holder'|'room' $groupBy
     * @param array<string, mixed>           $filters те же ключи, что у findFiltered, плюс `search`
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function findAggregated(string $groupBy, array $filters, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('s')
            ->join('s.nomenclature', 'n')
            ->leftJoin('s.holderUser', 'holderUser')
            ->leftJoin('s.holderWarehouse', 'holderWarehouse')
            ->leftJoin('s.room', 'room')
            // Нулевые и отрицательные строки в срез остатков не входят: ноль — это след
            // проведённого движения, а не наличие. Отсекаем в SQL, а не после выборки.
            ->andWhere('s.quantity > 0');

        // Алиас намеренно НЕ `quantity`: это имя поля сущности, и в DQL неквалифицированный
        // идентификатор в ORDER BY стал бы двусмысленным — поле против result variable.
        $select = ['IDENTITY(s.nomenclature) AS nomenclatureId', 'SUM(s.quantity) AS totalQuantity'];
        $qb->groupBy('s.nomenclature');

        if ($groupBy === 'holder') {
            $select[] = 'IDENTITY(s.holderUser) AS holderUserId';
            $select[] = 'IDENTITY(s.holderWarehouse) AS holderWarehouseId';
            $select[] = 'holderUser.lastname AS holderLastname';
            $select[] = 'holderUser.firstname AS holderFirstname';
            $select[] = 'holderUser.patronymic AS holderPatronymic';
            $select[] = 'holderWarehouse.name AS holderWarehouseName';
            $qb->addGroupBy('s.holderUser')
                ->addGroupBy('s.holderWarehouse')
                ->addGroupBy('holderUser.lastname')
                ->addGroupBy('holderUser.firstname')
                ->addGroupBy('holderUser.patronymic')
                ->addGroupBy('holderWarehouse.name');
        }

        if ($groupBy === 'room') {
            $select[] = 'IDENTITY(s.room) AS roomId';
            $select[] = 'room.name AS roomName';
            $qb->addGroupBy('s.room')->addGroupBy('room.name');
        }

        $qb->select(...$select)->orderBy('totalQuantity', 'DESC');

        $this->applyFilters($qb, $filters);

        /** @var list<array<string, mixed>> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        $total = \count($rows);

        return [
            'rows' => array_values(\array_slice($rows, ($page - 1) * $limit, $limit)),
            'total' => $total,
        ];
    }

    /**
     * Суммарный остаток позиции по всем разрезам. Нужен для сверки количества
     * с числом активных карточек устройств (Ф4): карточек не должно быть больше,
     * чем самих предметов на остатке.
     */
    public function sumQuantityByNomenclature(int $nomenclatureId): string
    {
        $sum = $this->createQueryBuilder('s')
            ->select('SUM(s.quantity)')
            ->where('s.nomenclature = :nomenclatureId')
            ->setParameter('nomenclatureId', $nomenclatureId)
            ->getQuery()
            ->getSingleScalarResult();

        return $sum === null ? '0' : (string) $sum;
    }

    private function andWhereNullableId(QueryBuilder $qb, string $field, string $parameter, ?int $value): void
    {
        if ($value === null) {
            $qb->andWhere(sprintf('%s IS NULL', $field));

            return;
        }

        $qb->andWhere(sprintf('%s = :%s', $field, $parameter))
            ->setParameter($parameter, $value);
    }
}
