<?php

namespace App\Repository\User;

use App\Entity\User\User;
use App\Entity\User\UserFile;
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
}
