<?php

namespace App\Repository\User;

use App\Entity\User\User;
use App\Entity\User\UserFolderFile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserFolderFile>
 */
class UserFolderFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserFolderFile::class);
    }

    /**
     * @return UserFolderFile[]
     */
    public function findByUserAndParent(User $user, ?UserFolderFile $parent): array
    {
        $qb = $this->createQueryBuilder('f')
            ->andWhere('f.user = :user')
            ->setParameter('user', $user)
            ->orderBy('f.name', 'ASC');

        if ($parent === null) {
            $qb->andWhere('f.parent IS NULL');
        } else {
            $qb->andWhere('f.parent = :parent')
                ->setParameter('parent', $parent);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return UserFolderFile[]
     */
    public function findAllByUser(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.user = :user')
            ->setParameter('user', $user)
            ->orderBy('f.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return UserFolderFile[]
     */
    public function getBreadcrumbs(UserFolderFile $folder): array
    {
        $breadcrumbs = [];
        $current = $folder;

        while ($current !== null) {
            array_unshift($breadcrumbs, $current);
            $current = $current->getParent();
        }

        return $breadcrumbs;
    }

    /**
     * @return int[]
     */
    public function getDescendantIds(UserFolderFile $folder): array
    {
        $ids = [];
        $this->collectDescendantIds($folder, $ids);

        return $ids;
    }

    private function collectDescendantIds(UserFolderFile $folder, array &$ids): void
    {
        $children = $this->findBy(['parent' => $folder]);

        foreach ($children as $child) {
            $ids[] = $child->getId();
            $this->collectDescendantIds($child, $ids);
        }
    }

    /**
     * @return array{folders: int, files: int}
     */
    public function countContentsRecursive(UserFolderFile $folder): array
    {
        $descendantIds = $this->getDescendantIds($folder);

        $em = $this->getEntityManager();
        $conn = $em->getConnection();

        $allFolderIds = array_merge([$folder->getId()], $descendantIds);
        $placeholders = implode(',', array_fill(0, count($allFolderIds), '?'));

        $fileCount = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM file_user WHERE folder_id IN ($placeholders)",
            $allFolderIds
        );

        return [
            'folders' => count($descendantIds),
            'files' => $fileCount,
        ];
    }
}
