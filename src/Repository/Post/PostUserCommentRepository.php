<?php

namespace App\Repository\Post;

use App\Entity\Post\Post;
use App\Entity\Post\PostUserComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PostUserComment>
 */
class PostUserCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PostUserComment::class);
    }

    /**
     * @param int[] $postIds
     * @return array<int, PostUserComment[]> grouped by post id
     */
    public function findLatestGroupedByPosts(array $postIds, int $perPost = 3): array
    {
        if (empty($postIds)) {
            return [];
        }

        $comments = $this->createQueryBuilder('c')
            ->addSelect('u')
            ->leftJoin('c.user', 'u')
            ->where('c.post IN (:postIds)')
            ->setParameter('postIds', $postIds)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($comments as $comment) {
            $postId = $comment->getPost()->getId();
            if (!isset($grouped[$postId])) {
                $grouped[$postId] = [];
            }
            if (count($grouped[$postId]) < $perPost) {
                $grouped[$postId][] = $comment;
            }
        }

        // Reverse to show oldest first within the latest 3
        foreach ($grouped as $postId => $items) {
            $grouped[$postId] = array_reverse($items);
        }

        return $grouped;
    }

    /**
     * @param int[] $postIds
     * @return array<int, int> post_id => count
     */
    public function countGroupedByPosts(array $postIds): array
    {
        if (empty($postIds)) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.post) AS post_id, COUNT(c.id) AS cnt')
            ->where('c.post IN (:postIds)')
            ->setParameter('postIds', $postIds)
            ->groupBy('c.post')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['post_id']] = (int) $row['cnt'];
        }

        return $result;
    }

    /**
     * @return PostUserComment[]
     */
    public function findByPostPaginated(Post $post, int $offset, int $limit = 5): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('u')
            ->leftJoin('c.user', 'u')
            ->where('c.post = :post')
            ->setParameter('post', $post)
            ->orderBy('c.createdAt', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
