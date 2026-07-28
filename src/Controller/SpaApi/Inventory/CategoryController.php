<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\Category;
use App\Repository\Inventory\CategoryRepository;
use App\Service\SpaApi\Inventory\InventoryAccessService;
use App\Service\SpaApi\Inventory\InventoryApiPresenter;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spa/api/inventory/categories')]
final class CategoryController extends AbstractController
{
    public function __construct(
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

        $result = $this->categories->findPaginated($page, $pageSize, $search);

        return $this->json([
            'categories' => array_map($this->presenter->category(...), $result['categories']),
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

        $category = new Category();
        $category->setName($name);
        $category->setRequiresDeviceCard((bool) ($payload['requires_device_card'] ?? false));
        $category->setSort((int) ($payload['sort'] ?? 0));

        $this->em->persist($category);
        $this->em->flush();

        return $this->json(['category' => $this->presenter->category($category)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PATCH'])]
    #[IsGranted('ROLE_INVENTORY_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        $category = $this->categories->find($id);
        if ($category === null) {
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
            $category->setName($name);
        }
        if (\array_key_exists('requires_device_card', $payload)) {
            $category->setRequiresDeviceCard((bool) $payload['requires_device_card']);
        }
        if (\array_key_exists('sort', $payload)) {
            $category->setSort((int) $payload['sort']);
        }
        if (\array_key_exists('is_active', $payload)) {
            $category->setIsActive((bool) $payload['is_active']);
        }

        $this->em->flush();

        return $this->json(['category' => $this->presenter->category($category)]);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_INVENTORY_ADMIN')]
    public function delete(int $id): JsonResponse
    {
        $category = $this->categories->find($id);
        if ($category === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->em->remove($category);
            $this->em->flush();
        } catch (ForeignKeyConstraintViolationException) {
            return $this->json(['error' => SpaApiError::INVENTORY_IN_USE], Response::HTTP_CONFLICT);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
