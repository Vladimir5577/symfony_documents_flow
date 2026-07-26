<?php

namespace App\Repository\Post;

use App\Entity\Post\Post;
use App\Entity\User\User;
use App\Enum\Post\PostType;
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

        return $qb->getQuery()->getResult();
    }

    public function countActive(?PostType $type, bool $isActive = true, ?User $author = null): int
    {
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

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
