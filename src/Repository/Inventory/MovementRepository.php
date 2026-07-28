<?php

namespace App\Repository\Inventory;

use App\Entity\Inventory\Device;
use App\Entity\Inventory\Document;
use App\Entity\Inventory\Movement;
use App\Enum\Inventory\DocumentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Movement>
 */
class MovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Movement::class);
    }

    /**
     * @return array<int, Movement>
     */
    public function findByDocument(Document|int $document): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.document = :document')
            ->setParameter('document', $document)
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, Movement>
     */
    public function findByDevice(Device|int $device): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.device = :device')
            ->setParameter('device', $device)
            ->orderBy('m.operationDate', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Текущая верхняя граница журнала движений.
     *
     * Сверка фиксирует её у себя, чтобы пересчёт через месяц дал тот же результат:
     * движения append-only, поэтому граница по id делает выборку воспроизводимой
     * даже после новых проведений.
     */
    public function currentMaxId(): ?int
    {
        $max = $this->createQueryBuilder('m')
            ->select('MAX(m.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return $max === null ? null : (int) $max;
    }

    /**
     * ID устройств, чьи приходящие движения попадали в указанные подразделения.
     *
     * Так определяется видимость карточки для начальника отдела: у устройства НЕТ поля
     * «подразделение» — оно всегда производное от движений. Правило намеренно «хоть раз
     * побывало», а не «находится сейчас»: начальник отдела отвечает и за историю того,
     * что через отдел проходило, а два разных правила для реестра и карточки давали бы
     * список, из которого часть строк не открывается.
     *
     * @param list<int> $departmentIds
     *
     * @return list<int>
     */
    public function findDeviceIdsInDepartments(array $departmentIds): array
    {
        if ($departmentIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('m')
            ->select('DISTINCT IDENTITY(m.device) AS deviceId')
            ->leftJoin('m.holderWarehouse', 'hw')
            ->leftJoin('hw.department', 'hwd')
            ->where('m.device IS NOT NULL')
            ->andWhere('m.direction = 1')
            // Сотрудник — по денормализованному снимку подразделения в самом движении;
            // склад — по подразделению склада.
            ->andWhere('(m.holderDepartmentId IN (:departmentIds) OR hwd.id IN (:departmentIds))')
            ->setParameter('departmentIds', $departmentIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(static fn (array $row): int => (int) $row['deviceId'], $rows));
    }

    /**
     * Отчёт по движениям за период: страница строк + общее число.
     *
     * Фильтр и пагинация — в SQL. Журнал движений растёт быстрее всех таблиц модуля,
     * и вытаскивать его целиком ради одной страницы нельзя.
     *
     * @param list<int>|null $warehouseIds  контур МОЛа; `null` — без ограничения, `[]` — ничего
     * @param list<int>|null $departmentIds скоуп начальника отдела; та же семантика
     *
     * @return array{rows: array<int, Movement>, total: int}
     */
    public function findForPeriod(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?array $warehouseIds,
        ?int $nomenclatureId,
        int $page,
        int $limit,
        ?array $departmentIds = null,
    ): array {
        $qb = $this->createQueryBuilder('m')
            ->join('m.nomenclature', 'n')
            ->addSelect('n')
            ->leftJoin('m.document', 'd')
            ->addSelect('d')
            ->where('m.operationDate >= :from')
            ->andWhere('m.operationDate <= :to')
            ->setParameter('from', \DateTimeImmutable::createFromInterface($from), Types::DATE_IMMUTABLE)
            ->setParameter('to', \DateTimeImmutable::createFromInterface($to), Types::DATE_IMMUTABLE)
            ->orderBy('m.operationDate', 'DESC')
            ->addOrderBy('m.id', 'DESC');

        if ($warehouseIds !== null) {
            if ($warehouseIds === []) {
                return ['rows' => [], 'total' => 0];
            }
            // Тот же скоуп склада, что и в сверке: выданное сотрудникам контура
            // остаётся ответственностью склада. Список, а не один id: у МОЛа может быть
            // несколько складов, и показывать только первый — терять половину журнала.
            $qb->andWhere('(m.holderWarehouse IN (:warehouseIds) OR m.managingWarehouse IN (:warehouseIds))')
                ->setParameter('warehouseIds', $warehouseIds, ArrayParameterType::INTEGER);
        }
        if ($departmentIds !== null) {
            if ($departmentIds === []) {
                return ['rows' => [], 'total' => 0];
            }
            // Скоуп начальника отдела: подразделение держателя (снимок в самом движении)
            // либо подразделение склада-держателя.
            $qb->leftJoin('m.holderWarehouse', 'hw2')
                ->leftJoin('hw2.department', 'hwd2')
                ->andWhere('(m.holderDepartmentId IN (:departmentIds) OR hwd2.id IN (:departmentIds))')
                ->setParameter('departmentIds', $departmentIds, ArrayParameterType::INTEGER);
        }
        if ($nomenclatureId !== null) {
            $qb->andWhere('m.nomenclature = :nomenclatureId')->setParameter('nomenclatureId', $nomenclatureId);
        }

        $countQb = clone $qb;
        $total = (int) $countQb
            ->select('COUNT(m.id)')
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
     * Расход материалов за период, свёрнутый по номенклатуре (и опционально по устройству).
     *
     * Нужен бухгалтерии для актов: в системе выдача расходника — сразу расход, и без
     * такого отчёта нечем подтвердить, куда за период ушли картриджи и комплектующие.
     *
     * @return list<array{nomenclatureId: int, deviceId: int|null, quantity: string, entries: int}>
     */
    public function consumptionReport(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?int $deviceId,
        bool $groupByDevice,
        ?array $warehouseIds = null,
        ?array $departmentIds = null,
    ): array {
        if ($warehouseIds === [] || $departmentIds === []) {
            return []; // скоуп пуст — показывать нечего, и подменять его на «всё» нельзя
        }

        $qb = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.nomenclature) AS nomenclatureId')
            ->addSelect('COALESCE(SUM(m.quantity), 0) AS quantity')
            ->addSelect('COUNT(m.id) AS entries')
            ->join('m.document', 'd')
            ->where('m.operationDate >= :from')
            ->andWhere('m.operationDate <= :to')
            ->andWhere('m.direction = :direction')
            ->andWhere('d.type = :type')
            ->groupBy('m.nomenclature')
            ->setParameter('from', \DateTimeImmutable::createFromInterface($from), Types::DATE_IMMUTABLE)
            ->setParameter('to', \DateTimeImmutable::createFromInterface($to), Types::DATE_IMMUTABLE)
            ->setParameter('direction', -1)
            ->setParameter('type', DocumentType::CONSUMPTION);

        if ($deviceId !== null) {
            $qb->andWhere('m.device = :deviceId')->setParameter('deviceId', $deviceId);
        }
        if ($warehouseIds !== null) {
            $qb->andWhere('(m.holderWarehouse IN (:warehouseIds) OR m.managingWarehouse IN (:warehouseIds))')
                ->setParameter('warehouseIds', $warehouseIds, ArrayParameterType::INTEGER);
        }
        if ($departmentIds !== null) {
            $qb->leftJoin('m.holderWarehouse', 'chw')
                ->leftJoin('chw.department', 'chwd')
                ->andWhere('(m.holderDepartmentId IN (:departmentIds) OR chwd.id IN (:departmentIds))')
                ->setParameter('departmentIds', $departmentIds, ArrayParameterType::INTEGER);
        }
        if ($groupByDevice) {
            $qb->addSelect('IDENTITY(m.device) AS deviceId')->addGroupBy('m.device');
        }

        $result = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $result[] = [
                'nomenclatureId' => (int) $row['nomenclatureId'],
                'deviceId' => isset($row['deviceId']) ? (int) $row['deviceId'] : null,
                'quantity' => (string) $row['quantity'],
                'entries' => (int) $row['entries'],
            ];
        }

        return $result;
    }

    /**
     * Срез остатков по всей номенклатуре склада на дату.
     *
     * Отдельный метод, а не цикл из qtyAtDate: по строкам файла нельзя найти позиции,
     * которые есть в системе, но отсутствуют в выгрузке 1С (`missing_in_1c`) — для них
     * нужен полный срез системы, а не обход строк файла.
     *
     * @return array<int, string> nomenclatureId => количество (decimal строкой)
     */
    public function qtyByNomenclatureAtDate(
        int $warehouseId,
        \DateTimeInterface $date,
        ?int $maxMovementId = null,
    ): array {
        $qb = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.nomenclature) AS nomenclature_id')
            ->addSelect('COALESCE(SUM(m.direction * m.quantity), 0) AS qty')
            ->where('m.operationDate <= :operationDate')
            ->andWhere('(m.holderWarehouse = :warehouseId OR m.managingWarehouse = :warehouseId)')
            ->groupBy('m.nomenclature')
            ->setParameter(
                'operationDate',
                \DateTimeImmutable::createFromInterface($date),
                Types::DATE_IMMUTABLE,
            )
            ->setParameter('warehouseId', $warehouseId);

        if ($maxMovementId !== null) {
            $qb->andWhere('m.id <= :maxMovementId')->setParameter('maxMovementId', $maxMovementId, Types::BIGINT);
        }

        $result = [];
        foreach ($qb->getQuery()->getResult() as $row) {
            $result[(int) $row['nomenclature_id']] = (string) $row['qty'];
        }

        return $result;
    }

    /**
     * Сколько номенклатуры ушло в расход по документам типа «Расход» на дату.
     *
     * Нужно для авто-объяснения расхождений по расходникам: в системе выдача расходника
     * сразу списывает его с остатка, а в 1С он может числиться до акта. Если разница
     * ровно равна расходу — это не пропажа, а разница методик учёта.
     *
     * @return array<int, string> nomenclatureId => списанное количество
     */
    public function consumptionByNomenclatureUpTo(
        int $warehouseId,
        \DateTimeInterface $date,
        ?int $maxMovementId = null,
    ): array {
        $qb = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.nomenclature) AS nomenclature_id')
            ->addSelect('COALESCE(SUM(m.quantity), 0) AS qty')
            ->join('m.document', 'd')
            ->where('m.operationDate <= :operationDate')
            ->andWhere('m.direction = :direction')
            ->andWhere('d.type = :type')
            ->andWhere('(m.holderWarehouse = :warehouseId OR m.managingWarehouse = :warehouseId)')
            ->groupBy('m.nomenclature')
            ->setParameter(
                'operationDate',
                \DateTimeImmutable::createFromInterface($date),
                Types::DATE_IMMUTABLE,
            )
            ->setParameter('direction', -1)
            ->setParameter('type', DocumentType::CONSUMPTION)
            ->setParameter('warehouseId', $warehouseId);

        if ($maxMovementId !== null) {
            $qb->andWhere('m.id <= :maxMovementId')->setParameter('maxMovementId', $maxMovementId, Types::BIGINT);
        }

        $result = [];
        foreach ($qb->getQuery()->getResult() as $row) {
            $result[(int) $row['nomenclature_id']] = (string) $row['qty'];
        }

        return $result;
    }

    /**
     * @param int|array<string, int|list<int>>|list<int>|null $warehouseScope
     */
    public function qtyAtDate(
        int $nomenclatureId,
        int|array|null $warehouseScope,
        \DateTimeInterface $date,
        ?int $maxMovementId = null,
    ): string {
        $qb = $this->createQueryBuilder('m')
            ->select('COALESCE(SUM(m.direction * m.quantity), 0)')
            ->where('m.nomenclature = :nomenclatureId')
            ->andWhere('m.operationDate <= :operationDate')
            ->setParameter('nomenclatureId', $nomenclatureId)
            ->setParameter(
                'operationDate',
                \DateTimeImmutable::createFromInterface($date),
                Types::DATE_IMMUTABLE,
            );

        if ($maxMovementId !== null) {
            $qb->andWhere('m.id <= :maxMovementId')->setParameter('maxMovementId', $maxMovementId, Types::BIGINT);
        }

        if (\is_int($warehouseScope)) {
            $qb->andWhere('(m.holderWarehouse = :warehouseId OR m.managingWarehouse = :warehouseId)')
                ->setParameter('warehouseId', $warehouseScope);
        } elseif ($warehouseScope !== null) {
            if (array_is_list($warehouseScope)) {
                if ($warehouseScope !== []) {
                    $qb->andWhere('(m.holderWarehouse IN (:warehouseIds) OR m.managingWarehouse IN (:warehouseIds))')
                        ->setParameter('warehouseIds', $warehouseScope, ArrayParameterType::INTEGER);
                }
            } else {
                $this->applyWarehouseScope($qb, $warehouseScope);
            }
        }

        return (string) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param array<string, int|list<int>> $warehouseScope
     */
    private function applyWarehouseScope(\Doctrine\ORM\QueryBuilder $qb, array $warehouseScope): void
    {
        $holderWarehouseId = $warehouseScope['holderWarehouseId'] ?? $warehouseScope['holder_warehouse_id'] ?? null;
        if (\is_int($holderWarehouseId)) {
            $qb->andWhere('m.holderWarehouse = :holderWarehouseId')
                ->setParameter('holderWarehouseId', $holderWarehouseId);
        }

        $managingWarehouseId = $warehouseScope['managingWarehouseId'] ?? $warehouseScope['managing_warehouse_id'] ?? null;
        if (\is_int($managingWarehouseId)) {
            $qb->andWhere('m.managingWarehouse = :managingWarehouseId')
                ->setParameter('managingWarehouseId', $managingWarehouseId);
        }

        $warehouseId = $warehouseScope['warehouseId'] ?? $warehouseScope['warehouse_id'] ?? null;
        if (\is_int($warehouseId)) {
            $qb->andWhere('(m.holderWarehouse = :warehouseId OR m.managingWarehouse = :warehouseId)')
                ->setParameter('warehouseId', $warehouseId);
        }

        $warehouseIds = $warehouseScope['warehouseIds'] ?? $warehouseScope['warehouse_ids'] ?? null;
        if (\is_array($warehouseIds) && $warehouseIds !== []) {
            $qb->andWhere('(m.holderWarehouse IN (:warehouseIds) OR m.managingWarehouse IN (:warehouseIds))')
                ->setParameter('warehouseIds', $warehouseIds, ArrayParameterType::INTEGER);
        }
    }
}
