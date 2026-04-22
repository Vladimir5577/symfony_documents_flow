<?php

namespace App\Repository\Analytics;

use App\Entity\Analytics\AnalyticsOrganization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsOrganization>
 */
class AnalyticsOrganizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsOrganization::class);
    }

    /**
     * Организации для таблицы сравнения: видимые, в заданном порядке.
     *
     * @return AnalyticsOrganization[]
     */
    public function findVisibleOrdered(): array
    {
        return $this->createQueryBuilder('ao')
            ->join('ao.organization', 'o')
            ->addSelect('o')
            ->andWhere('ao.isVisible = :visible')
            ->setParameter('visible', true)
            ->orderBy('ao.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
