<?php

namespace App\Repository\User;

use App\Entity\User\Role;
use App\Enum\UserRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Role>
 */
class RoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Role::class);
    }

    /**
     * Найти роль по имени (enum или строка).
     */
    public function findOneByName(UserRole|string $role): ?Role
    {
        $name = $role instanceof UserRole ? $role->value : $role;

        return $this->findOneBy(['name' => $name], ['id' => 'ASC']);
    }

    /**
     * Получить все роли кроме админской
     *
     * @return Role[]
     */
    public function findAllExceptAdmin(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.name != :adminRole')
            ->setParameter('adminRole', UserRole::ROLE_ADMIN->value)
            ->orderBy('r.sortOrder', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return Role[] Returns an array of Role objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('r.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Role
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
