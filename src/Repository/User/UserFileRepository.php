<?php

namespace App\Repository\User;

use App\Entity\User\User;
use App\Entity\User\UserFile;
use App\Entity\User\UserFolderFile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserFile>
 */
class UserFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserFile::class);
    }

    /**
     * @return UserFile[]
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(
            ['user' => $user],
            ['id' => 'ASC']
        );
    }

    /**
     * @return UserFile[]
     */
    public function findByUserAndFolder(User $user, ?UserFolderFile $folder): array
    {
        $qb = $this->createQueryBuilder('f')
            ->andWhere('f.user = :user')
            ->setParameter('user', $user)
            ->orderBy('f.title', 'ASC');

        if ($folder === null) {
            $qb->andWhere('f.folder IS NULL');
        } else {
            $qb->andWhere('f.folder = :folder')
                ->setParameter('folder', $folder);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return UserFile[]
     */
    public function searchByUser(User $user, string $query): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.folder', 'folder')
            ->addSelect('folder')
            ->andWhere('f.user = :user')
            ->andWhere('f.title LIKE :q OR f.originalName LIKE :q')
            ->setParameter('user', $user)
            ->setParameter('q', '%' . $query . '%')
            ->orderBy('f.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByFolder(UserFolderFile $folder): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.folder = :folder')
            ->setParameter('folder', $folder)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
