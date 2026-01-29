<?php

namespace App\Repository;

use App\Entity\DocumentHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentHistory>
 */
class DocumentHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentHistory::class);
    }

    /**
     * @return DocumentHistory[]
     */
    public function findByDocumentAndUserOrderByCreatedAtDesc(int $documentId, int $userId): array
    {
        return $this->createQueryBuilder('h')
            ->leftJoin('h.user', 'u')->addSelect('u')
            ->andWhere('h.document = :documentId')
            ->andWhere('h.user = :userId')
            ->setParameter('documentId', $documentId)
            ->setParameter('userId', $userId)
            ->orderBy('h.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return DocumentHistory[] Returns an array of DocumentHistory objects
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

    //    public function findOneBySomeField($value): ?DocumentHistory
    //    {
    //        return $this->createQueryBuilder('d')
    //            ->andWhere('d.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
