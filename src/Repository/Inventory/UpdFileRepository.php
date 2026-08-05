<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\Upd;
use App\Entity\Inventory\UpdFile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UpdFile>
 */
class UpdFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UpdFile::class);
    }

    /**
     * Файлы документа — в порядке загрузки: постраничный скан читают сверху вниз.
     *
     * @return UpdFile[]
     */
    public function findByUpd(Upd $upd): array
    {
        // Загрузивший (`ub`) идёт в ответ, его работник (`ubw`) — нет, но джойн обязателен:
        // worker — инверсная сторона OneToOne у User, прокси на неё построить нельзя,
        // и каждый файл стоил бы двух лишних запросов.
        return $this->createQueryBuilder('f')
            ->addSelect('ub', 'ubw')
            ->leftJoin('f.uploadedBy', 'ub')
            ->leftJoin('ub.worker', 'ubw')
            ->andWhere('f.upd = :upd')
            ->setParameter('upd', $upd)
            ->orderBy('f.createdAt', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
