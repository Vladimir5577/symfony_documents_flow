<?php

namespace App\Repository;

use App\Entity\AbstractOrganization;
use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AbstractOrganization>
 */
class OrganizationRepository extends ServiceEntityRepository
{
    public const ADMIN_ORGANIZATION_NAME = 'Admin Organization';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AbstractOrganization::class);
    }

    /**
     * Найти организацию по имени (исключая soft deleted)
     * Используется для валидации уникальности с учетом soft delete
     *
     * Фильтр soft delete применяется автоматически через глобальный фильтр Doctrine
     * UniqueEntity передает массив критериев и текущий объект как последний параметр при редактировании
     *
     * @param array<string, mixed> $criteria Массив критериев (ключ 'name' содержит название организации)
     * @param AbstractOrganization|null $excludeOrganization Организация, которую нужно исключить из проверки (для редактирования)
     * @return AbstractOrganization|null
     */
    public function findOneByName(array $criteria, ?AbstractOrganization $excludeOrganization = null): ?AbstractOrganization
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
     * Получить все родительские организации (где parent IS NULL)
     * Исключает админскую организацию
     *
     * @return Organization[]
     */
    public function findAllParentOrganizations(): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.parent IS NULL')
            ->andWhere('o.name != :adminName')
            ->setParameter('adminName', self::ADMIN_ORGANIZATION_NAME)
            ->orderBy('o.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Получить дерево организаций с дочерними (рекурсивно)
     * Для админа — все родительские организации с полным деревом дочерних.
     * Для обычного пользователя — организация пользователя и её дочерние
     * (если пользователь в дочерней организации — эта организация и её дети).
     *
     * @param Organization|null $userOrganization Организация пользователя (null для админа)
     * @return Organization[]
     */
    public function getOrganizationTree(?Organization $userOrganization = null): array
    {
        if ($userOrganization === null) {
            // Для админа — все родительские организации с полным деревом
            return $this->findAllParentOrganizations();
        }

        // Для обычного пользователя — его организация и дочерние к ней
        return [$userOrganization];
    }

    /**
     * Загрузить организацию со всеми дочерними организациями (рекурсивно, до 5 уровней)
     *
     * @param int $organizationId ID организации
     * @return Organization|null
     */
    public function findWithChildren(int $organizationId): ?Organization
    {
        return $this->createQueryBuilder('o')
            ->leftJoin('o.childOrganizations', 'co1')->addSelect('co1')
            ->leftJoin('co1.childOrganizations', 'co2')->addSelect('co2')
            ->leftJoin('co2.childOrganizations', 'co3')->addSelect('co3')
            ->leftJoin('co3.childOrganizations', 'co4')->addSelect('co4')
            ->leftJoin('co4.childOrganizations', 'co5')->addSelect('co5')
            ->where('o.id = :id')
            ->setParameter('id', $organizationId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Получить пагинированный список организаций.
     * Без поиска — только корневые (parent IS NULL). С поиском — по всем организациям (включая дочерние).
     *
     * @param int $page Номер страницы (начиная с 1)
     * @param int $limit Количество элементов на странице
     * @param string $search Поиск по названию, адресу, телефону, email (при непустом поиске ищем по всем уровням)
     * @return array{organizations: array, total: int, page: int, limit: int, totalPages: int}
     */
    public function findPaginated(int $page = 1, int $limit = 10, string $search = ''): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('o')
            ->where('o.name != :adminName')
            ->setParameter('adminName', self::ADMIN_ORGANIZATION_NAME)
            ->orderBy('o.id', 'ASC');

        $countQb = $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.name != :adminName')
            ->setParameter('adminName', self::ADMIN_ORGANIZATION_NAME);

        if ($search !== '') {
            $searchCondition = 'LOWER(o.name) LIKE LOWER(:search) OR LOWER(o.address) LIKE LOWER(:search) OR LOWER(o.phone) LIKE LOWER(:search) OR LOWER(o.email) LIKE LOWER(:search)';
            $qb->andWhere($searchCondition)->setParameter('search', '%' . $search . '%');
            $countQb->andWhere($searchCondition)->setParameter('search', '%' . $search . '%');
        } else {
            $qb->andWhere('o.parent IS NULL');
            $countQb->andWhere('o.parent IS NULL');
        }

        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $organizations = $qb
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $totalPages = $total > 0 ? (int) ceil($total / $limit) : 1;

        return [
            'organizations' => $organizations,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * Удаляет организацию по id (soft delete).
     * У дочерних организаций сбрасывает родителя (parent = null), чтобы они стали корневыми.
     *
     * @return bool true, если организация найдена и удалена, false если не найдена
     */
    public function deleteById(int $id): bool
    {
        $organization = $this->find($id);
        if (!$organization instanceof AbstractOrganization) {
            return false;
        }

        $em = $this->getEntityManager();
        foreach ($organization->getChildOrganizations() as $child) {
            $child->setParent(null);
        }

        $em->remove($organization);
        $em->flush();

        return true;
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
