<?php

namespace App\Repository\Kanban\Project;

use App\Entity\Kanban\Project\KanbanProject;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KanbanProject>
 */
class KanbanProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KanbanProject::class);
    }

    /**
     * Проекты, в которых пользователь владелец или участник.
     *
     * @return KanbanProject[]
     */
    public function findByMember(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('App\Entity\Kanban\Project\KanbanProjectUser', 'pu', 'WITH', 'pu.kanbanProject = p AND pu.user = :user')
            ->where('p.owner = :user')
            ->orWhere('pu.id IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('p.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return KanbanProject[] Returns an array of KanbanProject objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('k')
    //            ->andWhere('k.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('k.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?KanbanProject
    //    {
    //        return $this->createQueryBuilder('k')
    //            ->andWhere('k.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
