<?php

namespace App\Repository\Document;

use App\Entity\Document\DocumentUserRecipient;
use App\Entity\User\User;
use App\Enum\DocumentStatus;
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

    public function countNewIncomingForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->innerJoin('r.document', 'd')
            ->where('r.user = :user')
            ->andWhere('r.status = :status')
            ->andWhere('d.isPublished = :published')
            ->setParameter('user', $user)
            ->setParameter('status', DocumentStatus::NEW)
            ->setParameter('published', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findPaginatedByUser(User $user, int $page = 1, int $limit = 10): array
    {
        $offset = ($page - 1) * $limit;

        $total = (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->innerJoin('r.document', 'd')
            ->where('r.user = :user')
            ->andWhere('d.isPublished = :published')
            ->setParameter('user', $user)
            ->setParameter('published', true)
            ->getQuery()
            ->getSingleScalarResult();

        $recipients = $this->createQueryBuilder('r')
            ->innerJoin('r.document', 'd')->addSelect('d')
            ->leftJoin('d.documentType', 'dt')->addSelect('dt')
            ->leftJoin('d.organizationCreator', 'o')->addSelect('o')
            ->leftJoin('d.createdBy', 'cb')->addSelect('cb')
            ->where('r.user = :user')
            ->andWhere('d.isPublished = :published')
            ->setParameter('user', $user)
            ->setParameter('published', true)
            ->orderBy('d.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $totalPages = (int) ceil($total / $limit);

        return [
            'recipients' => $recipients,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $totalPages,
        ];
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
