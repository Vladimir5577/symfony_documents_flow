<?php

declare(strict_types=1);

namespace App\Controller\Analytics\TKO;

use App\Entity\Analytics\TKO\AnalyticsTkoVehicle;
use App\Entity\Organization\AbstractOrganization;
use App\Repository\Analytics\TKO\AnalyticsTkoVehicleRepository;
use App\Repository\Organization\OrganizationRepository;
use App\Repository\Polygon\PolygonRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_TKO')]
final class AnalyticsTkoVehicleController extends AbstractController
{
    private const PAGE_SIZE = 10;

    #[Route('/analytics/tko/vehicles', name: 'app_analytics_tko_vehicles', methods: ['GET'])]
    public function index(
        Request $request,
        AnalyticsTkoVehicleRepository $vehicleRepository,
        PolygonRepository $polygonRepository,
        OrganizationRepository $organizationRepository,
    ): Response {
        $page = max(1, $request->query->getInt('page', 1));
        $filterOrganizationId = $this->optionalPositiveInt($request->query->getString('organization_id'));
        $filterPolygonId = $this->optionalPositiveInt($request->query->getString('polygon_id'));
        $filters = [
            'organizationId' => $filterOrganizationId,
            'polygonId' => $filterPolygonId,
        ];
        $filterQuery = array_filter([
            'organization_id' => $filterOrganizationId,
            'polygon_id' => $filterPolygonId,
        ], static fn ($v) => null !== $v && 0 !== $v);

        $result = $vehicleRepository->findPage($page, self::PAGE_SIZE, $filters);

        $polygons = $polygonRepository->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'name' => 'ASC']);
        $filials = $organizationRepository->findAllParentOrganizations();

        $editing = null;
        $editId = $request->query->getInt('edit');
        if ($editId > 0) {
            $candidate = $vehicleRepository->find($editId);
            if ($candidate instanceof AnalyticsTkoVehicle) {
                $editing = $candidate;
            }
        }

        return $this->render('analytics/tko/fill_vehicles.html.twig', [
            'active_tab' => 'analytics_tko_vehicles',
            'vehicles' => $result['items'],
            'polygons' => $polygons,
            'filials' => $filials,
            'editing' => $editing,
            'pagination' => [
                'current_page' => $result['page'],
                'total_pages' => $result['totalPages'],
                'total_items' => $result['total'],
                'items_per_page' => $result['limit'],
            ],
            'filters' => [
                'organization_id' => $filterOrganizationId,
                'polygon_id' => $filterPolygonId,
            ],
            'filter_query' => $filterQuery,
        ]);
    }

    #[Route('/analytics/tko/vehicles/save', name: 'app_analytics_tko_vehicles_save', methods: ['POST'])]
    public function save(
        Request $request,
        AnalyticsTkoVehicleRepository $vehicleRepository,
        PolygonRepository $polygonRepository,
        OrganizationRepository $organizationRepository,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('analytics_tko_vehicle_save', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');

            return $this->redirectToRoute('app_analytics_tko_vehicles');
        }

        $vehicleId = $request->request->getInt('vehicle_id');
        $vehicle = $vehicleId > 0 ? $vehicleRepository->find($vehicleId) : null;
        if ($vehicleId > 0 && !$vehicle instanceof AnalyticsTkoVehicle) {
            $this->addFlash('error', 'ТС не найдено.');

            return $this->redirectToRoute('app_analytics_tko_vehicles');
        }

        $name = trim($request->request->getString('name'));
        if ('' === $name) {
            $this->addFlash('error', 'Укажите наименование ТС.');

            return $this->redirectAfterSave($vehicleId);
        }

        $licenseRaw = trim($request->request->getString('license_number'));
        if ('' === $licenseRaw) {
            $this->addFlash('error', 'Укажите госномер.');

            return $this->redirectAfterSave($vehicleId);
        }

        $polygonId = $request->request->getInt('polygon_id');
        $polygon = $polygonId > 0 ? $polygonRepository->find($polygonId) : null;
        if (null === $polygon) {
            $this->addFlash('error', 'Выберите полигон.');

            return $this->redirectAfterSave($vehicleId);
        }

        $organizationId = $request->request->getInt('organization_id');
        $organization = $organizationId > 0 ? $organizationRepository->find($organizationId) : null;
        if (!$organization instanceof AbstractOrganization || null !== $organization->getParent()) {
            $this->addFlash('error', 'Выберите филиал.');

            return $this->redirectAfterSave($vehicleId);
        }

        $isNew = null === $vehicle;
        if ($isNew) {
            $vehicle = new AnalyticsTkoVehicle();
        }

        $vehicle->setName($name);
        $vehicle->setLicenseNumber($licenseRaw);
        $vehicle->setPolygon($polygon);
        $vehicle->setOrganization($organization);
        $vehicle->setVolume($this->normalizeDecimal($request->request->getString('volume')));
        $vehicle->setCompactionRatio($this->normalizeDecimal($request->request->getString('compaction_ratio')));
        $vehicle->setIsActive($request->request->getBoolean('is_active'));

        $em->persist($vehicle);

        try {
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', 'ТС с таким госномером уже есть в справочнике.');

            return $this->redirectAfterSave($vehicleId);
        }

        $this->addFlash('success', $isNew ? 'ТС добавлено.' : 'ТС сохранено.');

        return $this->redirectToRoute('app_analytics_tko_vehicles');
    }

    #[Route('/analytics/tko/vehicles/{id}/delete', name: 'app_analytics_tko_vehicles_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        AnalyticsTkoVehicleRepository $vehicleRepository,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('analytics_tko_vehicle_delete_'.$id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');

            return $this->redirectToRoute('app_analytics_tko_vehicles');
        }

        $vehicle = $vehicleRepository->find($id);
        if (!$vehicle instanceof AnalyticsTkoVehicle) {
            $this->addFlash('error', 'ТС не найдено.');

            return $this->redirectToRoute('app_analytics_tko_vehicles');
        }

        $label = sprintf('%s (%s)', $vehicle->getLicenseNumber() ?? '', $vehicle->getName() ?? '');

        try {
            $em->remove($vehicle);
            $em->flush();
        } catch (ForeignKeyConstraintViolationException) {
            $this->addFlash('error', sprintf('Нельзя удалить %s: есть связанные ходки.', $label));

            return $this->redirectToRoute('app_analytics_tko_vehicles');
        }

        $this->addFlash('success', sprintf('ТС удалено: %s.', $label));

        return $this->redirectToRoute('app_analytics_tko_vehicles', [
            'page' => max(1, $request->request->getInt('page', 1)),
        ]);
    }

    private function redirectAfterSave(int $vehicleId): Response
    {
        if ($vehicleId > 0) {
            return $this->redirectToRoute('app_analytics_tko_vehicles', ['edit' => $vehicleId]);
        }

        return $this->redirectToRoute('app_analytics_tko_vehicles');
    }

    private function optionalPositiveInt(string $value): ?int
    {
        if ('' === $value || !ctype_digit($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
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
