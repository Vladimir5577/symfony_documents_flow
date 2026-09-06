<?php

namespace App\Repository\Post;

use App\Entity\Post\Post;
use App\Entity\Post\PostUserStatus;
use App\Entity\User\User;
use App\Enum\Post\PostType;
use App\Enum\Post\PostUserStatusType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
    public function findActivePaginated(
        ?PostType $type,
        int $page,
        int $limit = 10,
        bool $isActive = true,
        ?User $author = null,
        ?User $unacknowledgedFor = null,
    ): array {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('a')
            ->leftJoin('p.author', 'a')
            ->leftJoin('p.files', 'f')
            ->addSelect('f')
            ->where('p.isActive = :isActive')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('isActive', $isActive)
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ($author !== null) {
            $qb->andWhere('p.author = :author')
                ->setParameter('author', $author);
        }

        if ($type !== null) {
            $qb->andWhere('p.type = :type')
                ->setParameter('type', $type);
        }

        if ($unacknowledgedFor !== null) {
            $this->applyUnacknowledgedFilter($qb, $unacknowledgedFor);
        }

        return $qb->getQuery()->getResult();
    }

    public function countActive(
        ?PostType $type,
        bool $isActive = true,
        ?User $author = null,
        ?User $unacknowledgedFor = null,
    ): int {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.isActive = :isActive')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('isActive', $isActive);

        if ($author !== null) {
            $qb->andWhere('p.author = :author')
                ->setParameter('author', $author);
        }

        if ($type !== null) {
            $qb->andWhere('p.type = :type')
                ->setParameter('type', $type);
        }

        if ($unacknowledgedFor !== null) {
            $this->applyUnacknowledgedFilter($qb, $unacknowledgedFor);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Сколько активных обязательных публикаций сотрудник ещё не подтвердил —
     * по всей ленте, а не по странице (FE-17: блок «К ознакомлению» на фронте
     * считал только текущую страницу и рапортовал «вы ознакомились со всеми»).
     */
    public function countUnacknowledgedFor(User $user): int
    {
        return $this->countActive(null, true, null, $user);
    }

    /**
     * «Требуют ознакомления»: обязательная публикация без отметки ACKNOWLEDGED
     * у этого сотрудника. Тот же критерий, что у SPA-фильтра unacknowledged_only.
     */
    private function applyUnacknowledgedFilter(QueryBuilder $qb, User $user): void
    {
        $qb->andWhere('p.isRequiredAcknowledgment = true')
            ->andWhere(sprintf(
                'NOT EXISTS (SELECT 1 FROM %s s WHERE s.post = p AND s.user = :unackUser AND s.status = :unackStatus)',
                PostUserStatus::class,
            ))
            ->setParameter('unackUser', $user)
            ->setParameter('unackStatus', PostUserStatusType::ACKNOWLEDGED);
    }
}
