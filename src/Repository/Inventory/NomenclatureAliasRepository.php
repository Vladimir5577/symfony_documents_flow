<?php

namespace App\Repository\Inventory;

use App\Entity\Inventory\NomenclatureAlias;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NomenclatureAlias>
 */
class NomenclatureAliasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NomenclatureAlias::class);
    }

    public function findByRawName(string $rawName): ?NomenclatureAlias
    {
        return $this->findOneBy(['rawName' => $rawName]);
    }
}
