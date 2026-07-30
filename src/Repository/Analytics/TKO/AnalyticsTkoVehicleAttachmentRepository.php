<?php

declare(strict_types=1);

namespace App\Repository\Analytics\TKO;

use App\Entity\Analytics\TKO\AnalyticsTkoVehicle;
use App\Entity\Analytics\TKO\AnalyticsTkoVehicleAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsTkoVehicleAttachment>
 */
class AnalyticsTkoVehicleAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsTkoVehicleAttachment::class);
    }

    /**
     * @return AnalyticsTkoVehicleAttachment[]
     */
    public function findByVehicleAndContext(AnalyticsTkoVehicle $vehicle, string $context): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.vehicle = :vehicle')
            ->andWhere('a.context = :context')
            ->setParameter('vehicle', $vehicle)
            ->setParameter('context', $context)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
