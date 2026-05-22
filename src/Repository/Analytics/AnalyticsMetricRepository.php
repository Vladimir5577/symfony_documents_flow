<?php

namespace App\Repository\Analytics;

use App\Entity\Analytics\AnalyticsMetric;
use App\Enum\Analytics\AnalyticsMetricCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsMetric>
 */
class AnalyticsMetricRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsMetric::class);
    }

    /**
     * @return AnalyticsMetric[]
     */
    public function findFiltered(
        ?string $search,
        ?AnalyticsMetricCategory $category,
        ?string $type,
        ?bool $isActive,
    ): array {
        $qb = $this->createQueryBuilder('m');

        if ($search !== null && $search !== '') {
            $qb->andWhere('m.name LIKE :search OR m.businessKey LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        if ($category !== null) {
            $qb->andWhere('m.category = :category')->setParameter('category', $category);
        }

        if ($type !== null && $type !== '') {
            $qb->andWhere('m.type = :type')->setParameter('type', $type);
        }

        if ($isActive !== null) {
            $qb->andWhere('m.isActive = :isActive')->setParameter('isActive', $isActive);
        }

        return $qb->orderBy('m.id', 'ASC')->getQuery()->getResult();
    }

    /**
     * @return string[]
     */
    public function findDistinctTypes(): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('DISTINCT m.type')
            ->orderBy('m.type', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'type');
    }

    //    /**
    //     * @return AnalyticsMetric[] Returns an array of AnalyticsMetric objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('a')
    //            ->andWhere('a.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('a.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?AnalyticsMetric
    //    {
    //        return $this->createQueryBuilder('a')
    //            ->andWhere('a.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
