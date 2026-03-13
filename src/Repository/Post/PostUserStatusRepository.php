<?php

namespace App\Repository\Post;

use App\Entity\Post\PostUserStatus;
use App\Entity\User\User;
use App\Enum\Post\PostUserStatusType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PostUserStatus>
 */
class PostUserStatusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PostUserStatus::class);
    }

    /**
     * @param int[] $postIds
     * @return array<int, PostUserStatusType> post_id => status
     */
    public function findStatusesByPostsAndUser(array $postIds, User $user): array
    {
        if (empty($postIds)) {
            return [];
        }

        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.post) AS post_id, s.status')
            ->where('s.post IN (:postIds)')
            ->andWhere('s.user = :user')
            ->setParameter('postIds', $postIds)
            ->setParameter('user', $user)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['post_id']] = $row['status'];
        }

        return $result;
    }
}
