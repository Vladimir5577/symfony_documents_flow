<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseCategory;
use App\Entity\Purchase\PurchaseCategoryItem;
use App\Entity\User\User;
use App\Enum\User\UserRole;
use App\Repository\Purchase\PurchaseCategoryRepository;
use App\Repository\Purchase\PurchaseRequestRepository;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Справочник категорий закупок с номенклатурой.
 * Читают все участники процесса закупок, ведёт отдел закупок.
 */
#[Route('/spa/api/purchase-categories')]
final class PurchaseCategoryController extends AbstractController
{
    public function __construct(
        private readonly PurchaseCategoryRepository $categoryRepo,
        private readonly PurchaseRequestRepository $purchaseRepo,
        private readonly UserRepository $userRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'spa_api_purchase_categories_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->canRead()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'items' => array_map(
                fn (PurchaseCategory $category): array => $this->present($category),
                $this->categoryRepo->findAllOrdered(),
            ),
        ]);
    }

    #[Route('', name: 'spa_api_purchase_categories_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->canManage()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $name = $this->extractName($request);
        if ($name === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_CATEGORY_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        if ($this->categoryRepo->findOneBy(['name' => $name]) !== null) {
            return $this->json(['error' => SpaApiError::PURCHASE_CATEGORY_NAME_TAKEN], Response::HTTP_CONFLICT);
        }

        $category = new PurchaseCategory();
        $category->setName($name);
        $this->em->persist($category);
        $this->em->flush();

        return $this->json($this->present($category), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spa_api_purchase_categories_update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->canManage()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $category = $this->categoryRepo->find($id);
        if ($category === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_CATEGORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $name = $this->extractName($request);
        if ($name === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_CATEGORY_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        $existing = $this->categoryRepo->findOneBy(['name' => $name]);
        if ($existing !== null && $existing->getId() !== $category->getId()) {
            return $this->json(['error' => SpaApiError::PURCHASE_CATEGORY_NAME_TAKEN], Response::HTTP_CONFLICT);
        }

        $category->setName($name);
        $this->em->flush();

        return $this->json($this->present($category));
    }

    /** Удаление запрещено, пока на категорию ссылаются заявки. */
    #[Route('/{id}', name: 'spa_api_purchase_categories_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->canManage()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $category = $this->categoryRepo->find($id);
        if ($category === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_CATEGORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if ($this->purchaseRepo->count(['category' => $category]) > 0) {
            return $this->json(['error' => SpaApiError::PURCHASE_CATEGORY_IN_USE], Response::HTTP_CONFLICT);
        }

        $this->em->remove($category);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /** Назначить/снять ответственного за категорию: body {userId: int|null}. */
    #[Route('/{id}/responsible', name: 'spa_api_purchase_categories_responsible', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function setResponsible(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->canManage()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $category = $this->categoryRepo->find($id);
        if ($category === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_CATEGORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        $userId = is_array($payload) ? ($payload['userId'] ?? null) : null;

        if ($userId === null || $userId === '') {
            $category->setResponsibleUser(null);
        } else {
            $responsible = $this->userRepo->find((int) $userId);
            if ($responsible === null) {
                return $this->json(['error' => SpaApiError::USER_NOT_FOUND], Response::HTTP_BAD_REQUEST);
            }
            $category->setResponsibleUser($responsible);
        }

        $this->em->flush();

        return $this->json($this->present($category));
    }

    #[Route('/{id}/items', name: 'spa_api_purchase_categories_items_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function createItem(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->canManage()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $category = $this->categoryRepo->find($id);
        if ($category === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_CATEGORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $name = $this->extractName($request);
        if ($name === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_CATEGORY_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        if ($this->findItemByName($category, $name) !== null) {
            return $this->json(['error' => SpaApiError::PURCHASE_CATEGORY_NAME_TAKEN], Response::HTTP_CONFLICT);
        }

        $item = new PurchaseCategoryItem();
        $item->setName($name);
        $category->addItem($item);
        $this->em->persist($item);
        $this->em->flush();

        return $this->json($this->present($category), Response::HTTP_CREATED);
    }

    #[Route('/{id}/items/{itemId}', name: 'spa_api_purchase_categories_items_update', requirements: ['id' => '\d+', 'itemId' => '\d+'], methods: ['PUT'])]
    public function updateItem(int $id, int $itemId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->canManage()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $category = $this->categoryRepo->find($id);
        $item = $category !== null ? $this->findItem($category, $itemId) : null;
        if ($category === null || $item === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_CATEGORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $name = $this->extractName($request);
        if ($name === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_CATEGORY_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        $existing = $this->findItemByName($category, $name);
        if ($existing !== null && $existing->getId() !== $item->getId()) {
            return $this->json(['error' => SpaApiError::PURCHASE_CATEGORY_NAME_TAKEN], Response::HTTP_CONFLICT);
        }

        $item->setName($name);
        $this->em->flush();

        return $this->json($this->present($category));
    }

    #[Route('/{id}/items/{itemId}', name: 'spa_api_purchase_categories_items_delete', requirements: ['id' => '\d+', 'itemId' => '\d+'], methods: ['DELETE'])]
    public function deleteItem(int $id, int $itemId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->canManage()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $category = $this->categoryRepo->find($id);
        $item = $category !== null ? $this->findItem($category, $itemId) : null;
        if ($category === null || $item === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_CATEGORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $category->removeItem($item);
        $this->em->remove($item);
        $this->em->flush();

        return $this->json($this->present($category));
    }

    private function canRead(): bool
    {
        return $this->isGranted(UserRole::ROLE_MANAGER->value)
            || $this->isGranted(UserRole::ROLE_PURCHASE_DIRECTOR->value)
            || $this->isGranted(UserRole::ROLE_PURCHASE_DEPARTMENT->value);
    }

    private function canManage(): bool
    {
        return $this->isGranted(UserRole::ROLE_PURCHASE_DEPARTMENT->value);
    }

    private function extractName(Request $request): ?string
    {
        $payload = json_decode($request->getContent(), true);
        $name = is_array($payload) ? trim((string) ($payload['name'] ?? '')) : '';

        return $name !== '' && mb_strlen($name) <= 255 ? $name : null;
    }

    private function findItem(PurchaseCategory $category, int $itemId): ?PurchaseCategoryItem
    {
        foreach ($category->getItems() as $item) {
            if ($item->getId() === $itemId) {
                return $item;
            }
        }

        return null;
    }

    private function findItemByName(PurchaseCategory $category, string $name): ?PurchaseCategoryItem
    {
        foreach ($category->getItems() as $item) {
            if (mb_strtolower((string) $item->getName()) === mb_strtolower($name)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PurchaseCategory $category): array
    {
        $responsible = $category->getResponsibleUser();
        $responsibleName = $responsible !== null
            ? trim(($responsible->getLastname() ?? '') . ' ' . ($responsible->getFirstname() ?? ''))
            : '';

        return [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'responsible' => $responsible !== null
                ? [
                    'id' => $responsible->getId(),
                    'name' => $responsibleName !== '' ? $responsibleName : (string) $responsible->getLogin(),
                ]
                : null,
            'items' => array_map(
                fn (PurchaseCategoryItem $item): array => [
                    'id' => $item->getId(),
                    'name' => $item->getName(),
                ],
                $category->getItems()->toArray(),
            ),
        ];
    }
}
