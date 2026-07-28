<?php

declare(strict_types=1);

namespace App\Controller\Analytics\TKO;

use App\Entity\Analytics\TKO\AnalyticsTkoVehicle;
use App\Entity\Analytics\TKO\AnalyticsTkoVehicleTrip;
use App\Repository\Analytics\TKO\AnalyticsTkoVehicleRepository;
use App\Repository\Analytics\TKO\AnalyticsTkoVehicleTripRepository;
use App\Repository\Organization\OrganizationRepository;
use App\Repository\Polygon\PolygonRepository;
use App\Twig\TkoDecimalExtension;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_TKO')]
final class AnalyticsTkoVehicleTripController extends AbstractController
{
    private const GRID_PAGE_SIZE = 10;

    #[Route('/analytics/tko/vehicle-trips', name: 'app_analytics_tko_vehicle_trips', methods: ['GET'])]
    public function index(
        Request $request,
        AnalyticsTkoVehicleRepository $vehicleRepository,
        AnalyticsTkoVehicleTripRepository $tripRepository,
        OrganizationRepository $organizationRepository,
        PolygonRepository $polygonRepository,
    ): Response {
        $vehicles = $vehicleRepository->createQueryBuilder('v')
            ->andWhere('v.isActive = true')
            ->orderBy('v.licenseNumber', 'ASC')
            ->getQuery()
            ->getResult();

        $tripDate = $this->parseDate($request->query->getString('date')) ?? new \DateTimeImmutable('today');
        $vehicleId = $request->query->getInt('vehicle_id');
        $selectedVehicle = $vehicleId > 0 ? $vehicleRepository->find($vehicleId) : null;
        $page = max(1, $request->query->getInt('page', 1));

        $filterOrganizationId = $this->optionalPositiveInt($request->query->getString('organization_id'));
        $filterPolygonId = $this->optionalPositiveInt($request->query->getString('polygon_id'));
        $filterDateFrom = $this->parseDate($request->query->getString('date_from'));
        $filterDateTo = $this->parseDate($request->query->getString('date_to'));

        $filters = [
            'organizationId' => $filterOrganizationId,
            'polygonId' => $filterPolygonId,
            'dateFrom' => $filterDateFrom,
            'dateTo' => $filterDateTo,
        ];

        $filterQuery = array_filter([
            'organization_id' => $filterOrganizationId,
            'polygon_id' => $filterPolygonId,
            'date_from' => $filterDateFrom?->format('Y-m-d'),
            'date_to' => $filterDateTo?->format('Y-m-d'),
        ], static fn ($v) => null !== $v && '' !== $v && 0 !== $v);

        $existingWeights = '';
        if ($selectedVehicle instanceof AnalyticsTkoVehicle) {
            $trips = $tripRepository->createQueryBuilder('t')
                ->andWhere('t.vehicle = :vehicle')
                ->andWhere('t.tripDate = :date')
                ->setParameter('vehicle', $selectedVehicle)
                ->setParameter('date', $tripDate, Types::DATE_IMMUTABLE)
                ->orderBy('t.tripNumber', 'ASC')
                ->getQuery()
                ->getResult();

            $existingWeights = implode(' ', array_map(
                static fn (AnalyticsTkoVehicleTrip $t) => TkoDecimalExtension::format($t->getWeight()),
                $trips,
            ));
        }

        $grid = $tripRepository->findGridPage($page, self::GRID_PAGE_SIZE, $filters);

        return $this->render('analytics/tko/fill_vehicle_trips.html.twig', [
            'active_tab' => 'analytics_tko_vehicle_trips',
            'vehicles' => $vehicles,
            'tripDate' => $tripDate->format('Y-m-d'),
            'selectedVehicleId' => $selectedVehicle?->getId(),
            'weightsInput' => $existingWeights,
            'gridRows' => $grid['rows'],
            'maxTripNumber' => $grid['maxTripNumber'],
            'pagination' => [
                'current_page' => $grid['page'],
                'total_pages' => $grid['totalPages'],
                'total_items' => $grid['total'],
                'items_per_page' => $grid['limit'],
            ],
            'filials' => $organizationRepository->findAllParentOrganizations(),
            'polygons' => $polygonRepository->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'name' => 'ASC']),
            'filters' => [
                'organization_id' => $filterOrganizationId,
                'polygon_id' => $filterPolygonId,
                'date_from' => $filterDateFrom?->format('Y-m-d') ?? '',
                'date_to' => $filterDateTo?->format('Y-m-d') ?? '',
            ],
            'filter_query' => $filterQuery,
        ]);
    }

    #[Route('/analytics/tko/vehicle-trips/save', name: 'app_analytics_tko_vehicle_trips_save', methods: ['POST'])]
    public function save(
        Request $request,
        AnalyticsTkoVehicleRepository $vehicleRepository,
        AnalyticsTkoVehicleTripRepository $tripRepository,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('analytics_tko_vehicle_trip_save', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');

            return $this->redirectToRoute('app_analytics_tko_vehicle_trips');
        }

        $tripDate = $this->parseDate($request->request->getString('trip_date'));
        if (null === $tripDate) {
            $this->addFlash('error', 'Укажите корректную дату.');

            return $this->redirectToRoute('app_analytics_tko_vehicle_trips');
        }

        $vehicleId = $request->request->getInt('vehicle_id');
        $vehicle = $vehicleId > 0 ? $vehicleRepository->find($vehicleId) : null;
        if (!$vehicle instanceof AnalyticsTkoVehicle) {
            $this->addFlash('error', 'Выберите ТС.');

            return $this->redirectToRoute('app_analytics_tko_vehicle_trips', [
                'date' => $tripDate->format('Y-m-d'),
            ]);
        }

        $weightsRaw = trim($request->request->getString('weights'));
        $tokens = preg_split('/\s+/u', $weightsRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $weights = [];
        foreach ($tokens as $i => $token) {
            $normalized = $this->normalizeDecimal($token);
            if (null === $normalized) {
                $this->addFlash('error', sprintf('Значение «%s» (ходка %d) — не число.', $token, $i + 1));

                return $this->redirectToRoute('app_analytics_tko_vehicle_trips', [
                    'date' => $tripDate->format('Y-m-d'),
                    'vehicle_id' => $vehicleId,
                ]);
            }
            $weights[] = $normalized;
        }

        $existing = $tripRepository->createQueryBuilder('t')
            ->andWhere('t.vehicle = :vehicle')
            ->andWhere('t.tripDate = :date')
            ->setParameter('vehicle', $vehicle)
            ->setParameter('date', $tripDate, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();

        foreach ($existing as $trip) {
            $em->remove($trip);
        }
        $em->flush();

        foreach ($weights as $index => $weight) {
            $trip = new AnalyticsTkoVehicleTrip();
            $trip->setVehicle($vehicle);
            $trip->setTripDate($tripDate);
            $trip->setTripNumber($index + 1);
            $trip->setWeight($weight);
            $em->persist($trip);
        }
        $em->flush();

        $this->addFlash(
            'success',
            sprintf(
                'Сохранено: %s, %s — ходок: %d.',
                $vehicle->getLicenseNumber(),
                $tripDate->format('d.m.Y'),
                count($weights),
            ),
        );

        return $this->redirectToRoute('app_analytics_tko_vehicle_trips', [
            'date' => $tripDate->format('Y-m-d'),
            'vehicle_id' => $vehicleId,
        ]);
    }

    #[Route('/analytics/tko/vehicle-trips/delete', name: 'app_analytics_tko_vehicle_trips_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        AnalyticsTkoVehicleRepository $vehicleRepository,
        AnalyticsTkoVehicleTripRepository $tripRepository,
        EntityManagerInterface $em,
    ): Response {
        $vehicleId = $request->request->getInt('vehicle_id');
        $dateRaw = $request->request->getString('trip_date');
        $page = max(1, $request->request->getInt('page', 1));

        if (!$this->isCsrfTokenValid(
            'analytics_tko_vehicle_trip_delete_'.$vehicleId.'_'.$dateRaw,
            $request->request->getString('_token'),
        )) {
            $this->addFlash('error', 'Неверный CSRF-токен.');

            return $this->redirectToRoute('app_analytics_tko_vehicle_trips', ['page' => $page]);
        }

        $tripDate = $this->parseDate($dateRaw);
        $vehicle = $vehicleId > 0 ? $vehicleRepository->find($vehicleId) : null;
        if (null === $tripDate || !$vehicle instanceof AnalyticsTkoVehicle) {
            $this->addFlash('error', 'Не удалось удалить: ТС или дата не найдены.');

            return $this->redirectToRoute('app_analytics_tko_vehicle_trips', ['page' => $page]);
        }

        $trips = $tripRepository->createQueryBuilder('t')
            ->andWhere('t.vehicle = :vehicle')
            ->andWhere('t.tripDate = :date')
            ->setParameter('vehicle', $vehicle)
            ->setParameter('date', $tripDate, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();

        foreach ($trips as $trip) {
            $em->remove($trip);
        }
        $em->flush();

        $this->addFlash(
            'success',
            sprintf('Удалены ходки: %s, %s.', $vehicle->getLicenseNumber(), $tripDate->format('d.m.Y')),
        );

        return $this->redirectToRoute('app_analytics_tko_vehicle_trips', ['page' => $page]);
    }

    private function optionalPositiveInt(string $value): ?int
    {
        if ('' === $value || !ctype_digit($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
    }

    private function normalizeDecimal(string $value): ?string
    {
        $raw = trim($value);
        if ('' === $raw) {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], $raw);
        if (!is_numeric($normalized)) {
            return null;
        }

        return $normalized;
    }
}
