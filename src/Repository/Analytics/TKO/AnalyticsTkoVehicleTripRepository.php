<?php

declare(strict_types=1);

namespace App\Repository\Analytics\TKO;

use App\Entity\Analytics\TKO\AnalyticsTkoVehicleTrip;
use App\Twig\TkoDecimalExtension;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsTkoVehicleTrip>
 */
class AnalyticsTkoVehicleTripRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsTkoVehicleTrip::class);
    }

    /**
     * Сетка ходок с пагинацией по парам (дата + ТС).
     *
     * @param array{organizationId?: int|null, dateFrom?: \DateTimeImmutable|null, dateTo?: \DateTimeImmutable|null} $filters
     *
     * @return array{
     *     rows: list<array{vehicleId: int, date: string, dateIso: string, model: string, licenseNumber: string, organization: string, capacity: string, weights: array<int, string>}>,
     *     maxTripNumber: int,
     *     total: int,
     *     page: int,
     *     limit: int,
     *     totalPages: int
     * }
     */
    public function findGridPage(int $page, int $limit, array $filters = []): array
    {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $countQb = $this->createQueryBuilder('t')
            ->select('COUNT(DISTINCT CONCAT(IDENTITY(t.vehicle), \'|\', t.tripDate))')
            ->innerJoin('t.vehicle', 'v');
        $this->applyGridFilters($countQb, $filters);
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $totalPages = $total > 0 ? (int) ceil($total / $limit) : 1;
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $maxQb = $this->createQueryBuilder('t')
            ->select('MAX(t.tripNumber)')
            ->innerJoin('t.vehicle', 'v');
        $this->applyGridFilters($maxQb, $filters);
        $maxTripNumber = (int) ($maxQb->getQuery()->getSingleScalarResult() ?? 0);

        if (0 === $total) {
            return [
                'rows' => [],
                'maxTripNumber' => 0,
                'total' => 0,
                'page' => 1,
                'limit' => $limit,
                'totalPages' => 1,
            ];
        }

        $pairsQb = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.vehicle) AS vehicleId', 't.tripDate AS tripDate')
            ->innerJoin('t.vehicle', 'v')
            ->groupBy('t.vehicle', 't.tripDate')
            ->orderBy('t.tripDate', 'DESC')
            ->addOrderBy('t.vehicle', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);
        $this->applyGridFilters($pairsQb, $filters);
        $pairs = $pairsQb->getQuery()->getArrayResult();

        if ([] === $pairs) {
            return [
                'rows' => [],
                'maxTripNumber' => $maxTripNumber,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => $totalPages,
            ];
        }

        $orX = $this->getEntityManager()->getExpressionBuilder()->orX();
        $qb = $this->createQueryBuilder('t')
            ->innerJoin('t.vehicle', 'v')->addSelect('v')
            ->leftJoin('v.organization', 'o')->addSelect('o')
            ->orderBy('t.tripDate', 'DESC')
            ->addOrderBy('v.licenseNumber', 'ASC')
            ->addOrderBy('t.tripNumber', 'ASC');

        foreach ($pairs as $i => $pair) {
            $vehicleParam = 'vehicle_'.$i;
            $dateParam = 'date_'.$i;
            $orX->add($qb->expr()->andX(
                $qb->expr()->eq('t.vehicle', ':'.$vehicleParam),
                $qb->expr()->eq('t.tripDate', ':'.$dateParam),
            ));
            $qb->setParameter($vehicleParam, (int) $pair['vehicleId']);
            $qb->setParameter($dateParam, $pair['tripDate'], Types::DATE_IMMUTABLE);
        }
        $qb->andWhere($orX);

        /** @var AnalyticsTkoVehicleTrip[] $trips */
        $trips = $qb->getQuery()->getResult();

        $rows = [];
        foreach ($trips as $trip) {
            $vehicle = $trip->getVehicle();
            $tripDate = $trip->getTripDate();
            if (null === $vehicle || null === $vehicle->getId() || null === $tripDate) {
                continue;
            }

            $key = $vehicle->getId().'|'.$tripDate->format('Y-m-d');
            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'vehicleId' => $vehicle->getId(),
                    'date' => $tripDate->format('d.m.Y'),
                    'dateIso' => $tripDate->format('Y-m-d'),
                    'model' => $vehicle->getModel() ?? '',
                    'licenseNumber' => $vehicle->getLicenseNumber() ?? '',
                    'organization' => $vehicle->getOrganization()?->getName() ?? '—',
                    'capacity' => self::formatCapacity($vehicle->getVolume(), $vehicle->getCompactionRatio()),
                    'weights' => [],
                ];
            }

            $tripNumber = $trip->getTripNumber() ?? 0;
            if ($tripNumber > 0) {
                $rows[$key]['weights'][$tripNumber] = TkoDecimalExtension::format($trip->getWeight());
            }
        }

        $ordered = [];
        foreach ($pairs as $pair) {
            $date = $pair['tripDate'];
            $dateIso = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date;
            $key = ((int) $pair['vehicleId']).'|'.$dateIso;
            if (isset($rows[$key])) {
                $ordered[] = $rows[$key];
            }
        }

        return [
            'rows' => $ordered,
            'maxTripNumber' => $maxTripNumber,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * @param array{organizationId?: int|null, dateFrom?: \DateTimeImmutable|null, dateTo?: \DateTimeImmutable|null} $filters
     */
    private function applyGridFilters(QueryBuilder $qb, array $filters): void
    {
        $organizationId = $filters['organizationId'] ?? null;
        if (null !== $organizationId && $organizationId > 0) {
            $qb->andWhere('v.organization = :filterOrganization')
                ->setParameter('filterOrganization', $organizationId);
        }

        $dateFrom = $filters['dateFrom'] ?? null;
        if ($dateFrom instanceof \DateTimeImmutable) {
            $qb->andWhere('t.tripDate >= :filterDateFrom')
                ->setParameter('filterDateFrom', $dateFrom, Types::DATE_IMMUTABLE);
        }

        $dateTo = $filters['dateTo'] ?? null;
        if ($dateTo instanceof \DateTimeImmutable) {
            $qb->andWhere('t.tripDate <= :filterDateTo')
                ->setParameter('filterDateTo', $dateTo, Types::DATE_IMMUTABLE);
        }
    }

    private static function formatCapacity(?string $volume, ?string $compactionRatio): string
    {
        if (null === $volume || null === $compactionRatio || '' === $volume || '' === $compactionRatio) {
            return '—';
        }

        if (!is_numeric($volume) || !is_numeric($compactionRatio)) {
            return '—';
        }

        return TkoDecimalExtension::format((string) ((float) $volume * (float) $compactionRatio));
    }
}
