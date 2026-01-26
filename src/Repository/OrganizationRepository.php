<?php

namespace App\Repository;

use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Organization>
 */
class OrganizationRepository extends ServiceEntityRepository
{
    public const ADMIN_ORGANIZATION_NAME = 'Admin Organization';
    
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Organization::class);
    }

    /**
     * Найти организацию по имени (исключая soft deleted)
     * Используется для валидации уникальности с учетом soft delete
     *
     * Фильтр soft delete применяется автоматически через глобальный фильтр Doctrine
     * UniqueEntity передает массив критериев и текущий объект как последний параметр при редактировании
     *
     * @param array<string, mixed> $criteria Массив критериев (ключ 'name' содержит название организации)
     * @param Organization|null $excludeOrganization Организация, которую нужно исключить из проверки (для редактирования)
     * @return Organization|null
     */
    public function findOneByName(array $criteria, ?Organization $excludeOrganization = null): ?Organization
    {
        $name = $criteria['name'] ?? null;
        if ($name === null) {
            return null;
        }

        $qb = $this->createQueryBuilder('o')
            ->where('o.name = :name')
            ->setParameter('name', $name)
            ->orderBy('o.id', 'ASC')
            ->setMaxResults(1);

        if ($excludeOrganization !== null && $excludeOrganization->getId() !== null) {
            $qb->andWhere('o.id != :excludeId')
                ->setParameter('excludeId', $excludeOrganization->getId());
        }

        $result = $qb->getQuery()->getResult();
        return $result[0] ?? null;
    }

    /**
     * Получить пагинированный список организаций
     * Выводит только родительские организации (без дочерних)
     *
     * @param int $page Номер страницы (начиная с 1)
     * @param int $limit Количество элементов на странице
     * @return array{organizations: array, total: int, page: int, limit: int, totalPages: int}
     */
    public function findPaginated(int $page = 1, int $limit = 10): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('o')
            ->leftJoin('o.departments', 'd')
            ->addSelect('d')
            ->where('o.name != :adminName')
            ->andWhere('o.parent IS NULL')
            ->setParameter('adminName', self::ADMIN_ORGANIZATION_NAME)
            ->orderBy('o.id', 'ASC');

        // Получаем общее количество родительских организаций (исключая админскую)
        $total = (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.name != :adminName')
            ->andWhere('o.parent IS NULL')
            ->setParameter('adminName', self::ADMIN_ORGANIZATION_NAME)
            ->getQuery()
            ->getSingleScalarResult();

        // Получаем организации для текущей страницы
        $organizations = $qb
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $totalPages = (int) ceil($total / $limit);

        return [
            'organizations' => $organizations,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $totalPages,
        ];
    }

    //    /**
    //     * @return Organization[] Returns an array of Organization objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('o')
    //            ->andWhere('o.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('o.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Organization
    //    {
    //        return $this->createQueryBuilder('o')
    //            ->andWhere('o.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
