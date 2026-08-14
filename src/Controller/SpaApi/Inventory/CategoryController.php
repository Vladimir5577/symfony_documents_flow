<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\ItemCategory;
use App\Repository\Inventory\InventoryAccessRepository;
use App\Repository\Inventory\ItemCategoryRepository;
use App\Repository\Inventory\NomenclatureItemRepository;
use App\Repository\Inventory\NomenclatureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Справочник категорий. Читают все админы инвентаризации (нужен для фильтров и форм),
 * правит только главный администратор.
 */
#[Route('/spa/api/inventory/categories')]
#[IsGranted('ROLE_INVENTORY_MANAGER')]
final class CategoryController extends AbstractController
{
    public function __construct(
        private readonly ItemCategoryRepository $categoryRepository,
        private readonly NomenclatureItemRepository $itemRepository,
        private readonly NomenclatureRepository $nomenclatureRepository,
        private readonly InventoryAccessRepository $accessRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'spa_api_inventory_categories_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $onlyActive = $request->query->getBoolean('only_active');

        return $this->json([
            'categories' => array_map(
                fn (ItemCategory $category): array => $this->format($category),
                $this->categoryRepository->findAllOrdered($onlyActive),
            ),
        ]);
    }

    #[Route('', name: 'spa_api_inventory_categories_create', methods: ['POST'])]
    #[IsGranted('ROLE_INVENTORY_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => SpaApiError::INVENTORY_CATEGORY_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        if ($this->categoryRepository->findOneBy(['name' => $name]) !== null) {
            return $this->json(['error' => SpaApiError::INVENTORY_CATEGORY_NAME_EXISTS], Response::HTTP_CONFLICT);
        }

        $category = new ItemCategory();
        $category->setName(mb_substr($name, 0, 128));

        $this->em->persist($category);
        $this->em->flush();

        return $this->json(['category' => $this->format($category)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spa_api_inventory_categories_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    #[IsGranted('ROLE_INVENTORY_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        $category = $this->categoryRepository->find($id);
        if (!$category instanceof ItemCategory) {
            return $this->json(['error' => SpaApiError::INVENTORY_CATEGORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('name', $payload)) {
            $name = trim((string) $payload['name']);
            if ($name === '') {
                return $this->json(
                    ['error' => SpaApiError::INVENTORY_CATEGORY_NAME_REQUIRED],
                    Response::HTTP_BAD_REQUEST,
                );
            }

            $duplicate = $this->categoryRepository->findOneBy(['name' => $name]);
            if ($duplicate !== null && $duplicate->getId() !== $category->getId()) {
                return $this->json(['error' => SpaApiError::INVENTORY_CATEGORY_NAME_EXISTS], Response::HTTP_CONFLICT);
            }

            $category->setName(mb_substr($name, 0, 128));
        }

        if (array_key_exists('isActive', $payload)) {
            $category->setIsActive((bool) $payload['isActive']);
        }

        $this->em->flush();

        return $this->json(['category' => $this->format($category)]);
    }

    #[Route('/{id}', name: 'spa_api_inventory_categories_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[IsGranted('ROLE_INVENTORY_ADMIN')]
    public function delete(int $id): JsonResponse
    {
        $category = $this->categoryRepository->find($id);
        if (!$category instanceof ItemCategory) {
            return $this->json(['error' => SpaApiError::INVENTORY_CATEGORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        // Виды считаются отдельно от позиций: категория теперь висит на виде, и вид
        // без единой позиции всё равно держит внешний ключ. Без этой проверки наружу
        // ушла бы сырая ошибка RESTRICT вместо внятного 409.
        $itemsCount = $this->itemRepository->countByCategory($category);
        $nomenclatureCount = $this->nomenclatureRepository->countByCategory($category);
        $responsibleCount = $this->accessRepository->count(['category' => $category]);
        if ($itemsCount > 0 || $nomenclatureCount > 0 || $responsibleCount > 0) {
            return $this->json([
                'error' => SpaApiError::INVENTORY_CATEGORY_HAS_ITEMS,
                'itemsCount' => $itemsCount,
                'nomenclatureCount' => $nomenclatureCount,
                'responsibleCount' => $responsibleCount,
            ], Response::HTTP_CONFLICT);
        }

        $this->em->remove($category);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    /**
     * @return array{id: int|null, name: string, isActive: bool}
     */
    private function format(ItemCategory $category): array
    {
        return [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'isActive' => $category->isActive(),
        ];
    }
}
