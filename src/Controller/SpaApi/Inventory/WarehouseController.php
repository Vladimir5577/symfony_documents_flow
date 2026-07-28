<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\Warehouse;
use App\Entity\Organization\Department;
use App\Repository\Inventory\WarehouseRepository;
use App\Repository\Organization\OrganizationRepository;
use App\Repository\User\UserRepository;
use App\Service\SpaApi\Inventory\InventoryAccessService;
use App\Service\SpaApi\Inventory\InventoryApiPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spa/api/inventory/warehouses')]
final class WarehouseController extends AbstractController
{
    public function __construct(
        private readonly WarehouseRepository $warehouses,
        private readonly OrganizationRepository $organizations,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly InventoryAccessService $access,
        private readonly InventoryApiPresenter $presenter,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if (!$this->access->canViewDepartment()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->query->get('page_size', 10)));
        $search = trim((string) $request->query->get('search', ''));
        $departmentId = $request->query->has('department_id') ? (int) $request->query->get('department_id') : null;

        $result = $this->warehouses->findPaginated($page, $pageSize, $search, $departmentId);

        return $this->json([
            'warehouses' => array_map($this->presenter->warehouse(...), $result['warehouses']),
            'pagination' => $this->presenter->pagination($result['page'], $result['limit'], $result['total']),
        ]);
    }

    #[Route('', methods: ['POST'])]
    #[IsGranted('ROLE_INVENTORY_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => SpaApiError::INVENTORY_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        $error = $this->applyRelations($payload, $warehouse = new Warehouse(), requireBoth: true);
        if ($error !== null) {
            return $error;
        }

        $warehouse->setName($name);
        $warehouse->setNote($this->nullableString($payload['note'] ?? null));

        $this->em->persist($warehouse);
        $this->em->flush();

        return $this->json(['warehouse' => $this->presenter->warehouse($warehouse)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PATCH'])]
    #[IsGranted('ROLE_INVENTORY_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        $warehouse = $this->warehouses->find($id);
        if ($warehouse === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }

        if (\array_key_exists('name', $payload)) {
            $name = trim((string) $payload['name']);
            if ($name === '') {
                return $this->json(['error' => SpaApiError::INVENTORY_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
            }
            $warehouse->setName($name);
        }

        $error = $this->applyRelations($payload, $warehouse, requireBoth: false);
        if ($error !== null) {
            return $error;
        }

        if (\array_key_exists('note', $payload)) {
            $warehouse->setNote($this->nullableString($payload['note']));
        }
        if (\array_key_exists('is_active', $payload)) {
            $warehouse->setIsActive((bool) $payload['is_active']);
        }

        $this->em->flush();

        return $this->json(['warehouse' => $this->presenter->warehouse($warehouse)]);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_INVENTORY_ADMIN')]
    public function delete(int $id): JsonResponse
    {
        $warehouse = $this->warehouses->find($id);
        if ($warehouse === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($warehouse); // soft delete
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * department_id / responsible_user_id: обязательны при создании, опциональны при правке.
     * Узел организации обязан быть подразделением (STI discriminator = department) — CHECK
     * на уровне БД невозможен, проверяем здесь.
     *
     * @param array<string, mixed> $payload
     */
    private function applyRelations(array $payload, Warehouse $warehouse, bool $requireBoth): ?JsonResponse
    {
        if (\array_key_exists('department_id', $payload) || $requireBoth) {
            $department = $this->organizations->find((int) ($payload['department_id'] ?? 0));
            if (!$department instanceof Department) {
                return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
            }
            $warehouse->setDepartment($department);
        }

        if (\array_key_exists('responsible_user_id', $payload) || $requireBoth) {
            $responsible = $this->users->find((int) ($payload['responsible_user_id'] ?? 0));
            if ($responsible === null) {
                return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
            }
            $warehouse->setResponsibleUser($responsible);
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
