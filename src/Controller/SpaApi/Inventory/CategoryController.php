<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\ItemCategory;
use App\Repository\Inventory\InventoryAccessRepository;
use App\Repository\Inventory\ItemCategoryRepository;
use App\Repository\Inventory\NomenclatureItemRepository;
use App\Repository\Inventory\NomenclatureRepository;
use App\Service\Inventory\CategoryTree;
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
 *
 * Категории вложенные. Список отдаётся плоским, в порядке обхода дерева, с parentId,
 * уровнем и полным путём — дерево по нему фронт строит сам.
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

    /**
     * only_active отдаёт только то, что можно выбрать в форме: категория отключена
     * сама или у неё отключён кто-то из предков — её нет.
     */
    #[Route('', name: 'spa_api_inventory_categories_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $onlyActive = $request->query->getBoolean('only_active');
        $tree = $this->categoryRepository->loadTree();

        $categories = [];
        foreach ($tree->orderedIds() as $id) {
            if (!$onlyActive || $tree->isActive($id)) {
                $categories[] = $this->format($id, $tree);
            }
        }

        return $this->json(['categories' => $categories]);
    }

    #[Route('', name: 'spa_api_inventory_categories_create', methods: ['POST'])]
    #[IsGranted('ROLE_INVENTORY_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $name = mb_substr(trim((string) ($payload['name'] ?? '')), 0, 128);
        if ($name === '') {
            return $this->json(['error' => SpaApiError::INVENTORY_CATEGORY_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        [$parent, $parentError] = $this->resolveParent($payload['parentId'] ?? null);
        if ($parentError !== null) {
            return $parentError;
        }

        if ($parent !== null
            && $this->categoryRepository->loadTree()->depth((int) $parent->getId()) + 1 > CategoryTree::MAX_DEPTH
        ) {
            return $this->tooDeep();
        }

        if ($this->categoryRepository->findSibling($name, $parent) !== null) {
            return $this->json(['error' => SpaApiError::INVENTORY_CATEGORY_NAME_EXISTS], Response::HTTP_CONFLICT);
        }

        $category = new ItemCategory();
        $category->setName($name);
        $category->setParent($parent);

        $this->em->persist($category);
        $this->em->flush();

        return $this->json(
            ['category' => $this->format((int) $category->getId(), $this->categoryRepository->loadTree())],
            Response::HTTP_CREATED,
        );
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

        $name = $category->getName();
        if (array_key_exists('name', $payload)) {
            $name = mb_substr(trim((string) $payload['name']), 0, 128);
            if ($name === '') {
                return $this->json(
                    ['error' => SpaApiError::INVENTORY_CATEGORY_NAME_REQUIRED],
                    Response::HTTP_BAD_REQUEST,
                );
            }
        }

        // Перенос в другую ветку. Права ответственных и фильтры считаются по дереву
        // на лету, поэтому вместе с категорией переезжает всё, что под ней, без
        // единой правки позиций.
        $parent = $category->getParent();
        if (array_key_exists('parentId', $payload)) {
            [$parent, $parentError] = $this->resolveParent($payload['parentId']);
            if ($parentError !== null) {
                return $parentError;
            }

            if ($parent !== null) {
                $tree = $this->categoryRepository->loadTree();

                // Родителем не может быть сама категория или её потомок: ветка
                // замкнулась бы в кольцо и выпала из дерева.
                if (in_array((int) $parent->getId(), $tree->subtreeIds($id), true)) {
                    return $this->json(
                        ['error' => SpaApiError::INVENTORY_CATEGORY_PARENT_INVALID],
                        Response::HTTP_CONFLICT,
                    );
                }

                // Переезжает ветка целиком, поэтому считается её высота, а не один уровень.
                if ($tree->depth((int) $parent->getId()) + $tree->height($id) > CategoryTree::MAX_DEPTH) {
                    return $this->tooDeep();
                }
            }
        }

        if ($this->categoryRepository->findSibling($name, $parent, $id) !== null) {
            return $this->json(['error' => SpaApiError::INVENTORY_CATEGORY_NAME_EXISTS], Response::HTTP_CONFLICT);
        }

        $category->setName($name);
        $category->setParent($parent);

        if (array_key_exists('isActive', $payload)) {
            $category->setIsActive((bool) $payload['isActive']);
        }

        $this->em->flush();

        return $this->json(['category' => $this->format($id, $this->categoryRepository->loadTree())]);
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
        // без единой позиции всё равно держит внешний ключ. Подкатегории держат свой.
        // Без этих проверок наружу ушла бы сырая ошибка RESTRICT вместо внятного 409.
        $itemsCount = $this->itemRepository->countByCategory($category);
        $nomenclatureCount = $this->nomenclatureRepository->countByCategory($category);
        $responsibleCount = $this->accessRepository->count(['category' => $category]);
        $childrenCount = $this->categoryRepository->count(['parent' => $category]);
        if ($itemsCount > 0 || $nomenclatureCount > 0 || $responsibleCount > 0 || $childrenCount > 0) {
            return $this->json([
                'error' => SpaApiError::INVENTORY_CATEGORY_HAS_ITEMS,
                'itemsCount' => $itemsCount,
                'nomenclatureCount' => $nomenclatureCount,
                'responsibleCount' => $responsibleCount,
                'childrenCount' => $childrenCount,
            ], Response::HTTP_CONFLICT);
        }

        $this->em->remove($category);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    /**
     * Родитель из тела запроса. null или 0 — корневая категория.
     *
     * @return array{0: ItemCategory|null, 1: JsonResponse|null}
     */
    private function resolveParent(mixed $rawId): array
    {
        $parentId = (int) ($rawId ?? 0);
        if ($parentId <= 0) {
            return [null, null];
        }

        $parent = $this->categoryRepository->find($parentId);
        if (!$parent instanceof ItemCategory) {
            return [null, $this->json(['error' => SpaApiError::INVENTORY_CATEGORY_NOT_FOUND], Response::HTTP_NOT_FOUND)];
        }

        return [$parent, null];
    }

    private function tooDeep(): JsonResponse
    {
        return $this->json([
            'error' => SpaApiError::INVENTORY_CATEGORY_TOO_DEEP,
            'maxDepth' => CategoryTree::MAX_DEPTH,
        ], Response::HTTP_CONFLICT);
    }

    /**
     * isActive — флаг самой категории, его и переключают. available — можно ли её
     * выбрать в форме с учётом предков: отключённая «Оргтехника» гасит всю ветку.
     *
     * @return array{id: int, name: string, parentId: int|null, path: string, level: int, isActive: bool, available: bool}
     */
    private function format(int $id, CategoryTree $tree): array
    {
        return [
            'id' => $id,
            'name' => $tree->name($id),
            'parentId' => $tree->parentId($id),
            'path' => $tree->path($id),
            'level' => $tree->depth($id),
            'isActive' => $tree->isOwnActive($id),
            'available' => $tree->isActive($id),
        ];
    }
}
