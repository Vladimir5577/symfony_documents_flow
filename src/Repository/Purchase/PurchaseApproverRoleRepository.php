<?php

declare(strict_types=1);

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseApproverRole;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseRoleCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseApproverRole>
 */
class PurchaseApproverRoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseApproverRole::class);
    }

    /**
     * Роли модуля, выданные этому человеку. Строк на человека единицы, поэтому
     * берём связки целиком и разворачиваем в коды на месте.
     *
     * @return list<PurchaseRoleCode>
     */
    public function findRoleCodesForUser(User $user): array
    {
        /** @var list<PurchaseApproverRole> $links */
        $links = $this->createQueryBuilder('ar')
            ->innerJoin('ar.approver', 'a')
            ->andWhere('a.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $codes = [];
        foreach ($links as $link) {
            $code = $link->getRoleCode();
            if ($code !== null) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Носители перечисленных ролей — для рассылки уведомлений на ролевой шаг.
     *
     * Выбираем связки, а не пользователей: корень запроса — эта таблица, и
     * `SELECT u` без корневого алиаса Doctrine не разбирает. Пользователи
     * приезжают fetch-join'ом, поэтому запрос всё равно один, а дубли (человек с
     * двумя ролями из списка) снимаются по id.
     *
     * @param list<PurchaseRoleCode> $codes
     * @return list<User>
     */
    public function findUsersByRoleCodes(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        /** @var list<PurchaseApproverRole> $links */
        $links = $this->createQueryBuilder('ar')
            ->select('ar', 'a', 'u')
            ->innerJoin('ar.approver', 'a')
            ->innerJoin('a.user', 'u')
            ->andWhere('ar.roleCode IN (:codes)')
            ->setParameter(
                'codes',
                array_map(static fn (PurchaseRoleCode $code): string => $code->value, $codes),
                ArrayParameterType::STRING,
            )
            ->getQuery()
            ->getResult();

        $users = [];
        foreach ($links as $link) {
            $user = $link->getApprover()?->getUser();
            if ($user !== null) {
                $users[(int) $user->getId()] = $user;
            }
        }

        return array_values($users);
    }
}
