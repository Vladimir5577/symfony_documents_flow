<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\Nomenclature;
use App\Enum\Inventory\Unit;
use App\Repository\Inventory\CategoryRepository;
use App\Repository\Inventory\NomenclatureRepository;
use App\Service\SpaApi\Inventory\InventoryAccessService;
use App\Service\SpaApi\Inventory\InventoryApiPresenter;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spa/api/inventory/nomenclature')]
final class NomenclatureController extends AbstractController
{
    public function __construct(
        private readonly NomenclatureRepository $nomenclatures,
        private readonly CategoryRepository $categories,
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
        $categoryId = $request->query->has('category_id') ? (int) $request->query->get('category_id') : null;
        $isConsumable = $request->query->has('is_consumable') ? $request->query->getBoolean('is_consumable') : null;

        $result = $this->nomenclatures->findPaginated($page, $pageSize, $search, $categoryId, $isConsumable);

        return $this->json([
            'nomenclature' => array_map($this->presenter->nomenclature(...), $result['nomenclatures']),
            'pagination' => $this->presenter->pagination($result['page'], $result['limit'], $result['total']),
        ]);
    }

    #[Route('', methods: ['POST'])]
    #[IsGranted('ROLE_INVENTORY_ADMIN')]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => SpaApiError::INVENTORY_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }
        $category = $this->categories->find((int) ($payload['category_id'] ?? 0));
        if ($category === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }
        $unit = Unit::tryFrom((string) ($payload['unit'] ?? Unit::PCS->value));
        if ($unit === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }

        $nomenclature = new Nomenclature();
        $nomenclature->setName($name);
        $nomenclature->setCategory($category);
        $nomenclature->setUnit($unit);
        $nomenclature->setIsConsumable((bool) ($payload['is_consumable'] ?? false));
        $nomenclature->setCode1c($this->nullableString($payload['code_1c'] ?? null));
        $nomenclature->setNote($this->nullableString($payload['note'] ?? null));
        $nomenclature->setCreatedBy($user);

        $this->em->persist($nomenclature);
        $this->em->flush();

        return $this->json(['nomenclature' => $this->presenter->nomenclature($nomenclature)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PATCH'])]
    #[IsGranted('ROLE_INVENTORY_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        $nomenclature = $this->nomenclatures->find($id);
        if ($nomenclature === null) {
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
            $nomenclature->setName($name);
        }
        if (\array_key_exists('category_id', $payload)) {
            $category = $this->categories->find((int) $payload['category_id']);
            if ($category === null) {
                return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
            }
            $nomenclature->setCategory($category);
        }
        if (\array_key_exists('unit', $payload)) {
            $unit = Unit::tryFrom((string) $payload['unit']);
            if ($unit === null) {
                return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
            }
            $nomenclature->setUnit($unit);
        }
        if (\array_key_exists('is_consumable', $payload)) {
            $nomenclature->setIsConsumable((bool) $payload['is_consumable']);
        }
        if (\array_key_exists('code_1c', $payload)) {
            $nomenclature->setCode1c($this->nullableString($payload['code_1c']));
        }
        if (\array_key_exists('note', $payload)) {
            $nomenclature->setNote($this->nullableString($payload['note']));
        }

        $this->em->flush();

        return $this->json(['nomenclature' => $this->presenter->nomenclature($nomenclature)]);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_INVENTORY_ADMIN')]
    public function delete(int $id): JsonResponse
    {
        $nomenclature = $this->nomenclatures->find($id);
        if ($nomenclature === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        // Gedmo SoftDeleteable: остаётся в остатках/движениях, скрывается из справочника
        $this->em->remove($nomenclature);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
