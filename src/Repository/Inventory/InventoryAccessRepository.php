<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\InventoryAccess;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InventoryAccess>
 */
class InventoryAccessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryAccess::class);
    }

    /**
     * Все привязки пользователя — и «админ организации», и «ответственный за категорию».
     *
     * @return InventoryAccess[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('o', 'c')
            ->join('a.organization', 'o')
            ->leftJoin('a.category', 'c')
            ->andWhere('a.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }

    /**
     * Привязки в пределах перечисленных организаций — список для админа.
     *
     * @param int[] $organizationIds
     *
     * @return InventoryAccess[]
     */
    public function findByOrganizationIds(array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->addSelect('u', 'o', 'c')
            ->join('a.user', 'u')
            ->join('a.organization', 'o')
            ->leftJoin('a.category', 'c')
            ->andWhere('a.organization IN (:organizationIds)')
            ->setParameter('organizationIds', $organizationIds)
            ->orderBy('o.name', 'ASC')
            ->addOrderBy('u.lastname', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return InventoryAccess[]
     */
    public function findAllWithRelations(): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('u', 'o', 'c')
            ->join('a.user', 'u')
            ->join('a.organization', 'o')
            ->leftJoin('a.category', 'c')
            ->orderBy('o.name', 'ASC')
            ->addOrderBy('u.lastname', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
