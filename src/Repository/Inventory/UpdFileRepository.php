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
        // Загрузивший (`ub`) идёт в ответ: без джойна каждый файл стоил бы лишнего запроса.
        return $this->createQueryBuilder('f')
            ->addSelect('ub')
            ->leftJoin('f.uploadedBy', 'ub')
            ->andWhere('f.upd = :upd')
            ->setParameter('upd', $upd)
            ->orderBy('f.createdAt', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
