<?php

namespace App\Repository\Kanban\Project;

use App\Entity\Kanban\Project\KanbanProject;
use App\Entity\Kanban\Project\KanbanProjectUser;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KanbanProjectUser>
 */
class KanbanProjectUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KanbanProjectUser::class);
    }

    public function findByProjectAndUser(KanbanProject $project, User $user): ?KanbanProjectUser
    {
        return $this->createQueryBuilder('pu')
            ->where('pu.kanbanProject = :project')
            ->andWhere('pu.user = :user')
            ->setParameter('project', $project)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return KanbanProjectUser[]
     */
    public function findByProject(KanbanProject $project): array
    {
        return $this->createQueryBuilder('pu')
            ->innerJoin('pu.user', 'u')->addSelect('u')
            ->where('pu.kanbanProject = :project')
            ->setParameter('project', $project)
            ->orderBy('u.lastname')
            ->addOrderBy('u.firstname')
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return KanbanProjectUser[] Returns an array of KanbanProjectUser objects
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

    //    public function findOneBySomeField($value): ?KanbanProjectUser
    //    {
    //        return $this->createQueryBuilder('k')
    //            ->andWhere('k.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
