<?php

namespace App\Repository\Analytics\TKO;

use App\Entity\Analytics\TKO\AnalyticsTKO;
use App\Entity\Polygon\Polygon;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsTKO>
 */
class AnalyticsTKORepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsTKO::class);
    }

    /**
     * Записи полигона за диапазон дат (неделю), отсортированные по дате.
     *
     * @return AnalyticsTKO[]
     */
    public function findByPolygonAndDateRange(Polygon $polygon, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.polygon = :polygon')
            ->andWhere('a.reportDate BETWEEN :start AND :end')
            ->setParameter('polygon', $polygon)
            ->setParameter('start', $start, Types::DATE_IMMUTABLE)
            ->setParameter('end', $end, Types::DATE_IMMUTABLE)
            ->orderBy('a.reportDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByPolygonAndDate(Polygon $polygon, \DateTimeImmutable $date): ?AnalyticsTKO
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.polygon = :polygon')
            ->andWhere('a.reportDate = :date')
            ->setParameter('polygon', $polygon)
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
