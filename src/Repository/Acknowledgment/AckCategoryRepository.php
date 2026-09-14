<?php

declare(strict_types=1);

namespace App\Repository\Acknowledgment;

use App\Entity\Acknowledgment\AckCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AckCategory>
 */
class AckCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AckCategory::class);
    }

    /**
     * @return AckCategory[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.sort', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Есть ли документы в категории — иначе её удаление уронило бы RESTRICT. */
    public function hasDocuments(AckCategory $category): bool
    {
        $count = (int) $this->getEntityManager()
            ->createQuery('SELECT COUNT(d.id) FROM App\Entity\Acknowledgment\AckDocument d WHERE d.category = :category')
            ->setParameter('category', $category)
            ->getSingleScalarResult();

        return $count > 0;
    }
}
