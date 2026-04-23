<?php

namespace App\Repository\Document;

use App\Entity\Document\Document;
use App\Entity\User\User;
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

    /**
     * Пагинированный список документов, созданных пользователем (исходящие).
     *
     * @param User $user
     * @param int $page Номер страницы (начиная с 1)
     * @param int $limit Количество элементов на странице
     * @return array{documents: array, total: int, page: int, limit: int, totalPages: int}
     */
    /**
     * @param array{
     *     typeId?: ?int,
     *     name?: ?string,
     * } $filters
     */
    public function findPaginatedByCreatedBy(User $user, int $page = 1, int $limit = 10, array $filters = []): array
    {
        $offset = ($page - 1) * $limit;

        $countQb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.createdBy = :user')
            ->setParameter('user', $user);

        $listQb = $this->createQueryBuilder('d')
            ->leftJoin('d.documentType', 'dt')->addSelect('dt')
            ->leftJoin('d.organizationCreator', 'o')->addSelect('o')
            ->leftJoin('d.createdBy', 'cb')->addSelect('cb')
            ->where('d.createdBy = :user')
            ->setParameter('user', $user)
            ->orderBy('d.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        foreach ([$countQb, $listQb] as $qb) {
            if (!empty($filters['typeId'])) {
                $qb->andWhere('d.documentType = :typeId')->setParameter('typeId', $filters['typeId']);
            }
            if (!empty($filters['name'])) {
                $qb->andWhere('LOWER(d.name) LIKE :name')
                    ->setParameter('name', '%' . mb_strtolower(trim($filters['name'])) . '%');
            }
        }

        $total = (int) $countQb->getQuery()->getSingleScalarResult();
        $documents = $listQb->getQuery()->getResult();

        $totalPages = (int) ceil($total / $limit);

        return [
            'documents' => $documents,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $totalPages,
        ];
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
