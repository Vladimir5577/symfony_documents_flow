<?php

namespace App\Repository;

use App\Entity\DocumentUserRecipient;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentUserRecipient>
 */
class DocumentUserRecipientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentUserRecipient::class);
    }

    /**
     * Входящие документы пользователя (где он получатель).
     *
     * @return DocumentUserRecipient[]
     */
    public function findByUserWithDocument(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.document', 'd')->addSelect('d')
            ->leftJoin('d.documentType', 'dt')->addSelect('dt')
            ->leftJoin('d.organizationCreator', 'o')->addSelect('o')
            ->leftJoin('d.createdBy', 'cb')->addSelect('cb')
            ->where('r.user = :user')
            ->setParameter('user', $user)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return DocumentUserRecipient[] Returns an array of DocumentUserRecipient objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('d')
    //            ->andWhere('d.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('d.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?DocumentUserRecipient
    //    {
    //        return $this->createQueryBuilder('d')
    //            ->andWhere('d.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
