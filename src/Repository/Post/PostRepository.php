<?php

namespace App\Repository\Post;

use App\Entity\Post\Post;
use App\Entity\Post\PostUserStatus;
use App\Entity\User\User;
use App\Enum\Post\PostType;
use App\Enum\Post\PostUserStatusType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Post>
 */
class PostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    /**
     * @return Post[]
     */
    public function findActivePaginated(?PostType $type, int $page, int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('a')
            ->leftJoin('p.author', 'a')
            ->leftJoin('p.files', 'f')
            ->addSelect('f')
            ->where('p.isActive = true')
            ->andWhere('p.deletedAt IS NULL')
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ($type !== null) {
            $qb->andWhere('p.type = :type')
                ->setParameter('type', $type);
        }

        return $qb->getQuery()->getResult();
    }

    public function countActive(?PostType $type): int
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.isActive = true')
            ->andWhere('p.deletedAt IS NULL');

        if ($type !== null) {
            $qb->andWhere('p.type = :type')
                ->setParameter('type', $type);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Активные публикации для SPA-ленты, отсортированные по дате создания (сначала новые).
     *
     * При $unacknowledgedOnly возвращаются только требующие ознакомления публикации,
     * которые текущий пользователь ещё не подтвердил.
     *
     * @return Post[]
     */
    public function findActiveForSpaPaginated(
        ?PostType $type,
        int $page,
        int $limit,
        User $user,
        bool $unacknowledgedOnly,
    ): array {
        $qb = $this->createActiveForSpaQueryBuilder($type, $user, $unacknowledgedOnly)
            ->addSelect('a')
            ->leftJoin('p.author', 'a')
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /**
     * Количество активных публикаций для SPA-ленты с теми же фильтрами,
     * что и {@see findActiveForSpaPaginated()}.
     */
    public function countActiveForSpa(
        ?PostType $type,
        User $user,
        bool $unacknowledgedOnly,
    ): int {
        $qb = $this->createActiveForSpaQueryBuilder($type, $user, $unacknowledgedOnly)
            ->select('COUNT(DISTINCT p.id)');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Базовый билдер для SPA: активные, не удалённые публикации нужного типа.
     * При $unacknowledgedOnly добавляет фильтр «требуют ознакомления и не подтверждены пользователем».
     */
    private function createActiveForSpaQueryBuilder(
        ?PostType $type,
        User $user,
        bool $unacknowledgedOnly,
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('p')
            ->where('p.isActive = true')
            ->andWhere('p.deletedAt IS NULL');

        if ($type !== null) {
            $qb->andWhere('p.type = :type')
                ->setParameter('type', $type);
        }

        if ($unacknowledgedOnly) {
            $qb->leftJoin(
                PostUserStatus::class,
                'us',
                Join::WITH,
                'us.post = p AND us.user = :user',
            )
                ->andWhere('p.isRequiredAcknowledgment = true')
                ->andWhere($qb->expr()->orX(
                    'us.id IS NULL',
                    'us.status <> :acknowledged',
                ))
                ->setParameter('user', $user)
                ->setParameter('acknowledged', PostUserStatusType::ACKNOWLEDGED);
        }

        return $qb;
    }
}
