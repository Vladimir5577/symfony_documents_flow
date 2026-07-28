<?php

namespace App\Repository\Inventory;

use App\Entity\Inventory\EmployeeAlias;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmployeeAlias>
 */
class EmployeeAliasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmployeeAlias::class);
    }

    public function findByRawFio(string $rawFio): ?EmployeeAlias
    {
        return $this->findOneBy(['rawFio' => $rawFio]);
    }
}
