<?php

namespace App\Repository\Document;

use App\Entity\Document\DocumentHistory;
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


}
