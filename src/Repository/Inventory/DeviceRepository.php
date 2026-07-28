<?php

namespace App\Repository\Inventory;

use App\Entity\Inventory\Device;
use App\Enum\Inventory\DeviceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Device>
 */
class DeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Device::class);
    }

    /**
     * Сколько активных карточек заведено на позицию. Списанные не считаем: их предметы
     * с остатка уже ушли, и в предупреждение о перевязке они попадать не должны.
     * Мягко удалённые отсекает фильтр softdeleteable.
     */
    public function countActiveByNomenclature(int $nomenclatureId): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.nomenclature = :nomenclatureId')
            ->andWhere('d.status != :writtenOff')
            ->setParameter('nomenclatureId', $nomenclatureId)
            ->setParameter('writtenOff', DeviceStatus::WRITTEN_OFF)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<int>|null $visibleIds ограничение видимости; `null` — без ограничений
     *
     * @return array{devices: array<int, Device>, total: int, page: int, limit: int, totalPages: int}
     */
    public function findPaginated(
        int $page = 1,
        int $limit = 10,
        string $search = '',
        DeviceStatus|string|null $status = null,
        ?int $nomenclatureId = null,
        ?array $visibleIds = null,
    ): array {
        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.nomenclature', 'nomenclature')
            ->addSelect('nomenclature')
            ->orderBy('d.name', 'ASC');

        $countQb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)');

        if ($search !== '') {
            $condition = '(LOWER(d.name) LIKE LOWER(:search) OR LOWER(d.ipAddress) LIKE LOWER(:search) OR LOWER(d.serialNumber) LIKE LOWER(:search))';
            $qb->andWhere($condition)->setParameter('search', '%' . $search . '%');
            $countQb->andWhere($condition)->setParameter('search', '%' . $search . '%');
        }

        $statusEnum = \is_string($status) ? DeviceStatus::tryFrom($status) : $status;
        if ($statusEnum !== null) {
            $qb->andWhere('d.status = :status')->setParameter('status', $statusEnum);
            $countQb->andWhere('d.status = :status')->setParameter('status', $statusEnum);
        }

        if ($nomenclatureId !== null) {
            $qb->andWhere('d.nomenclature = :nomenclatureId')->setParameter('nomenclatureId', $nomenclatureId);
            $countQb->andWhere('d.nomenclature = :nomenclatureId')->setParameter('nomenclatureId', $nomenclatureId);
        }

        // Скоуп видимости (начальник отдела). `null` — ограничений нет; пустой список
        // означает «видно ничего», и подменять его на «видно всё» нельзя.
        if ($visibleIds !== null) {
            if ($visibleIds === []) {
                return ['devices' => [], 'total' => 0, 'page' => $page, 'limit' => $limit, 'totalPages' => 1];
            }
            $qb->andWhere('d.id IN (:visibleIds)')->setParameter('visibleIds', $visibleIds);
            $countQb->andWhere('d.id IN (:visibleIds)')->setParameter('visibleIds', $visibleIds);
        }

        $total = (int) $countQb->getQuery()->getSingleScalarResult();
        $devices = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [
            'devices' => $devices,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $total > 0 ? (int) ceil($total / $limit) : 1,
        ];
    }
}
