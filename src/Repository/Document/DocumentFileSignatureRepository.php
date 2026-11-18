<?php

declare(strict_types=1);

namespace App\Repository\Document;

use App\Entity\Document\DocumentFileSignature;
use App\Entity\Document\File;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentFileSignature>
 */
class DocumentFileSignatureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentFileSignature::class);
    }

    /** @return list<DocumentFileSignature> */
    public function findForFile(File $file): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('u')
            ->join('s.user', 'u')
            ->andWhere('s.file = :file')
            ->setParameter('file', $file)
            ->orderBy('s.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
