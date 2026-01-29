<?php

namespace App\Repository;

use App\Entity\Document;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Document>
 */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /**
     * Документ по ID со всеми связями для страницы просмотра.
     */
    public function findOneWithRelations(int $id): ?Document
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.documentType', 'dt')->addSelect('dt')
            ->leftJoin('d.organizationCreator', 'o')->addSelect('o')
            ->leftJoin('d.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('d.userRecipients', 'ur')->addSelect('ur')
            ->leftJoin('ur.user', 'u')->addSelect('u')
            ->where('d.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Документы, созданные пользователем (исходящие).
     *
     * @return Document[]
     */
    public function findByCreatedBy(User $user): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.documentType', 'dt')->addSelect('dt')
            ->leftJoin('d.organizationCreator', 'o')->addSelect('o')
            ->leftJoin('d.createdBy', 'cb')->addSelect('cb')
            ->where('d.createdBy = :user')
            ->setParameter('user', $user)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return Document[] Returns an array of Document objects
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

    //    public function findOneBySomeField($value): ?Document
    //    {
    //        return $this->createQueryBuilder('d')
    //            ->andWhere('d.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
