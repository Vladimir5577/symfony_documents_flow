<?php

namespace App\Repository\User;

use App\Entity\Organization\AbstractOrganization;
use App\Entity\User\User;
use App\Enum\UserEmployeeStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * @param string $identifier
     * @return User|null
     *
     * Критично: подгрузка ролей и их permissions одним запросом
     *
     * Иначе получите N+1 при is_granted().
     */
    public function loadUserByIdentifier(string $identifier): ?User
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.userRoles', 'ur')->addSelect('ur')
            ->leftJoin('ur.role', 'r')->addSelect('r')
            ->where('u.login = :id')
            ->setParameter('id', $identifier)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Получить пагинированный список пользователей
     *
     * @param int $page Номер страницы (начиная с 1)
     * @param int $limit Количество элементов на странице
     * @param string $search Поиск по ФИО, логину, телефону
     * @param int|null $organizationId Фильтр по организации (null = все)
     * @param string|null $status Фильтр по статусу (enum value, null = все)
     * @return array{users: array, total: int, page: int, limit: int, totalPages: int}
     */
    public function findPaginated(int $page = 1, int $limit = 10, string $search = '', ?int $organizationId = null, ?string $status = null): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('u')
            ->orderBy('u.id', 'ASC');

        $countQb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)');

        if ($search !== '') {
            $searchCondition = 'LOWER(u.lastname) LIKE LOWER(:search) OR LOWER(u.firstname) LIKE LOWER(:search) OR LOWER(u.patronymic) LIKE LOWER(:search) OR LOWER(u.login) LIKE LOWER(:search) OR LOWER(u.phone) LIKE LOWER(:search)';
            $qb->andWhere($searchCondition)->setParameter('search', '%' . $search . '%');
            $countQb->andWhere($searchCondition)->setParameter('search', '%' . $search . '%');
        }

        if ($organizationId !== null && $organizationId > 0) {
            $qb->andWhere('u.organization = :orgId')->setParameter('orgId', $organizationId);
            $countQb->andWhere('u.organization = :orgId')->setParameter('orgId', $organizationId);
        }

        $statusEnum = $status !== null && $status !== '' ? UserEmployeeStatus::tryFrom($status) : null;
        if ($statusEnum !== null) {
            $qb->andWhere('u.userEmployeeStatus = :status')->setParameter('status', $statusEnum);
            $countQb->andWhere('u.userEmployeeStatus = :status')->setParameter('status', $statusEnum);
        }

        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $users = $qb
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $totalPages = $total > 0 ? (int) ceil($total / $limit) : 1;

        return [
            'users' => $users,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $totalPages,
        ];
    }

    //    /**
    //     * @return User[] Returns an array of User objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('u.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    /**
     * Получить всех активных (не удаленных) пользователей
     *
     * @return User[]
     */
    public function findAllActive(): array
    {
        return $this->findAll();
    }

    /**
     * Пользователи, которые работают с документами в указанной организации.
     *
     * @return User[]
     */
    public function findWorkWithDocumentsByOrganization(AbstractOrganization $organization): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.organization = :org')
            ->andWhere('u.workWithDocuments = :flag')
            ->setParameter('org', $organization)
            ->setParameter('flag', true)
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Пользователи, которые работают с документами.
     *
     * @return User[]
     */
    public function findAllWorkWithDocuments(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.workWithDocuments = :flag')
            ->setParameter('flag', true)
            ->orderBy('u.lastname', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Найти пользователей по списку id (для отображения выбранных в форме).
     *
     * @param int[] $ids
     * @return User[]
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ids = array_map('intval', array_filter($ids));
        if ($ids === []) {
            return [];
        }
        return $this->createQueryBuilder('u')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Пользователи организации (с подгрузкой ролей для отображения).
     *
     * @return User[]
     */
    public function findByOrganization(AbstractOrganization $organization): array
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.userRoles', 'ur')->addSelect('ur')
            ->leftJoin('ur.role', 'r')->addSelect('r')
            ->where('u.organization = :org')
            ->setParameter('org', $organization)
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Soft delete: обнуляет ссылки на пользователя у подчинённых, затем устанавливает deleted_at.
     * onDelete: SET NULL не срабатывает при soft delete (нет реального DELETE в БД).
     *
     * @return bool true если пользователь удалён, false если не найден или уже удалён
     */
    public function softDelete(int $id): bool
    {
        $user = $this->find($id);
        if (!$user || $user->isDeleted()) {
            return false;
        }

        $em = $this->getEntityManager();

        // Обнулить boss_id, created_by_id, updated_by_id у ссылающихся пользователей
        $this->createQueryBuilder('u')
            ->update()
            ->set('u.boss', ':null')
            ->where('u.boss = :user')
            ->setParameter('null', null)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();

        $this->createQueryBuilder('u')
            ->update()
            ->set('u.createdBy', ':null')
            ->where('u.createdBy = :user')
            ->setParameter('null', null)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();

        $this->createQueryBuilder('u')
            ->update()
            ->set('u.updatedBy', ':null')
            ->where('u.updatedBy = :user')
            ->setParameter('null', null)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();

        $em->remove($user);
        $em->flush();

        return true;
    }

    /**
     * Найти активного пользователя по ID
     *
     * @param int $id
     * @return User|null
     */
    public function findActive(int $id): ?User
    {
        return $this->find($id);
    }

    /**
     * Найти пользователя по логину (исключая soft deleted).
     * Используется для валидации уникальности UniqueEntity.
     * При редактировании исключает текущего пользователя из проверки.
     *
     * @param array<string, mixed> $criteria Критерии (ключ 'login')
     * @param User|null $excludeUser Пользователь, которого исключить (при редактировании)
     * @return User|null
     */
    public function findOneByLogin(array $criteria, ?User $excludeUser = null): ?User
    {
        $login = $criteria['login'] ?? null;
        if ($login === null || $login === '') {
            return null;
        }

        $qb = $this->createQueryBuilder('u')
            ->where('u.login = :login')
            ->setParameter('login', $login)
            ->orderBy('u.id', 'ASC')
            ->setMaxResults(1);

        if ($excludeUser !== null && $excludeUser->getId() !== null) {
            $qb->andWhere('u.id != :excludeId')
                ->setParameter('excludeId', $excludeUser->getId());
        }

        $result = $qb->getQuery()->getResult();

        return $result[0] ?? null;
    }

    //    public function findOneBySomeField($value): ?User
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
