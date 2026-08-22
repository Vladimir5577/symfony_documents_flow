<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\NomenclatureItem;
use App\Entity\Inventory\NomenclatureHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NomenclatureHistory>
 */
class NomenclatureHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NomenclatureHistory::class);
    }

    /**
     * @return NomenclatureHistory[]
     */
    public function findByItem(NomenclatureItem $item): array
    {
        // Работники (`*w`) в ответе не нужны, но без них Doctrine делает по SELECT
        // на каждого из трёх пользователей каждой записи: worker — инверсная сторона
        // OneToOne у User, прокси на неё построить нельзя.
        return $this->createQueryBuilder('h')
            ->addSelect('u', 'uw', 'oa', 'oaw', 'na', 'naw')
            ->leftJoin('h.changedBy', 'u')
            ->leftJoin('u.worker', 'uw')
            ->leftJoin('h.oldAssignedTo', 'oa')
            ->leftJoin('oa.worker', 'oaw')
            ->leftJoin('h.newAssignedTo', 'na')
            ->leftJoin('na.worker', 'naw')
            ->andWhere('h.item = :item')
            ->setParameter('item', $item)
            ->orderBy('h.createdAt', 'DESC')
            ->addOrderBy('h.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
