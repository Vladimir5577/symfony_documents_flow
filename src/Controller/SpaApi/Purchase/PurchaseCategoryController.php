<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseCategory;
use App\Entity\Purchase\PurchaseCategoryItem;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseCapability;
use App\Repository\Purchase\PurchaseCategoryRepository;
use App\Repository\Purchase\PurchaseRequestRepository;
use App\Service\Purchase\PurchaseAccess;
use App\Service\Purchase\PurchaseFileStorageService;
use App\Service\Purchase\PurchaseImageUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
        private readonly EntityManagerInterface $em,
        private readonly PurchaseFileStorageService $storage,
        private readonly PurchaseImageUrlGenerator $imageUrlGenerator,
        private readonly PurchaseAccess $access,
    ) {
    }

    #[Route('', name: 'spa_api_purchase_categories_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // Читают ВСЕ авторизованные: справочник нужен форме создания заявки,
        // а создать заявку может любой сотрудник. Ролевой гейт здесь давал
        // обычному пользователю 403 прямо на форме — и финдиректору тоже.
        // Ведёт справочник по-прежнему только отдел закупок, см. canManage().
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

        // Строки уедут каскадом, а объекты в бакете искать потом будет уже нечем:
        // ключи хранятся только в них.
        $this->storage->delete($category->getImageKey());
        foreach ($category->getItems() as $item) {
            $this->storage->delete($item->getImageKey());
        }

        $this->em->remove($category);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
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

        $this->storage->delete($item->getImageKey());

        $category->removeItem($item);
        $this->em->remove($item);
        $this->em->flush();

        return $this->json($this->present($category));
    }

    /**
     * Вывести категорию из оборота или вернуть обратно: body {active: bool}.
     * Позиции гасятся вместе с ней сами — их собственные флаги не трогаем.
     */
    #[Route('/{id}/active', name: 'spa_api_purchase_categories_active', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function setActive(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
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

        $category->setActive($this->extractActive($request));
        $this->em->flush();

        return $this->json($this->present($category));
    }

    /** Вывести позицию из оборота или вернуть обратно: body {active: bool}. */
    #[Route('/{id}/items/{itemId}/active', name: 'spa_api_purchase_categories_items_active', requirements: ['id' => '\d+', 'itemId' => '\d+'], methods: ['PUT'])]
    public function setItemActive(int $id, int $itemId, Request $request, #[CurrentUser] ?User $user): JsonResponse
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

        $item->setActive($this->extractActive($request));
        $this->em->flush();

        return $this->json($this->present($category));
    }

    /** Картинка категории: multipart, поле `image`. Повторная загрузка заменяет прежнюю. */
    #[Route('/{id}/image', name: 'spa_api_purchase_categories_image_set', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function setImage(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
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

        $uploaded = $request->files->get('image');
        if (!$uploaded instanceof UploadedFile) {
            return $this->json(['error' => SpaApiError::FILE_NOT_PROVIDED], Response::HTTP_BAD_REQUEST);
        }

        $error = $this->imageError($uploaded);
        if ($error !== null) {
            return $this->json(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        // Прежний ключ сносим ПОСЛЕ успешной записи нового: упади putObject раньше —
        // и категория осталась бы совсем без картинки.
        $oldKey = $category->getImageKey();
        $category->setImageKey($this->storage->uploadImage(sprintf('categories/%d', $id), $uploaded));
        $this->em->flush();
        $this->storage->delete($oldKey);

        return $this->json($this->present($category));
    }

    #[Route('/{id}/image', name: 'spa_api_purchase_categories_image_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function deleteImage(int $id, #[CurrentUser] ?User $user): JsonResponse
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

        $this->storage->delete($category->getImageKey());
        $category->setImageKey(null);
        $this->em->flush();

        return $this->json($this->present($category));
    }

    /** Картинка позиции номенклатуры: multipart, поле `image`. */
    #[Route('/{id}/items/{itemId}/image', name: 'spa_api_purchase_categories_items_image_set', requirements: ['id' => '\d+', 'itemId' => '\d+'], methods: ['POST'])]
    public function setItemImage(int $id, int $itemId, Request $request, #[CurrentUser] ?User $user): JsonResponse
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

        $uploaded = $request->files->get('image');
        if (!$uploaded instanceof UploadedFile) {
            return $this->json(['error' => SpaApiError::FILE_NOT_PROVIDED], Response::HTTP_BAD_REQUEST);
        }

        $error = $this->imageError($uploaded);
        if ($error !== null) {
            return $this->json(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $oldKey = $item->getImageKey();
        $item->setImageKey($this->storage->uploadImage(sprintf('categories/%d/items/%d', $id, $itemId), $uploaded));
        $this->em->flush();
        $this->storage->delete($oldKey);

        return $this->json($this->present($category));
    }

    #[Route('/{id}/items/{itemId}/image', name: 'spa_api_purchase_categories_items_image_delete', requirements: ['id' => '\d+', 'itemId' => '\d+'], methods: ['DELETE'])]
    public function deleteItemImage(int $id, int $itemId, #[CurrentUser] ?User $user): JsonResponse
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

        $this->storage->delete($item->getImageKey());
        $item->setImageKey(null);
        $this->em->flush();

        return $this->json($this->present($category));
    }

    /** @return string|null код ошибки или null, если файл годится */
    private function imageError(UploadedFile $file): ?string
    {
        if ($file->getSize() > PurchaseFileStorageService::MAX_IMAGE_SIZE) {
            return SpaApiError::POST_FILE_TOO_LARGE;
        }

        return $this->storage->isAllowedImage($file) ? null : SpaApiError::PURCHASE_IMAGE_INVALID_TYPE;
    }

    /**
     * Справочник ведёт тот, у кого полномочие на справочники модуля.
     * ROLE_ADMIN проходит его всегда — это делает PurchaseRoster.
     */
    private function canManage(): bool
    {
        $user = $this->getUser();

        return $user instanceof User
            && $this->access->can($user, PurchaseCapability::MANAGE_DICTIONARIES);
    }

    private function extractName(Request $request): ?string
    {
        $payload = json_decode($request->getContent(), true);
        $name = is_array($payload) ? trim((string) ($payload['name'] ?? '')) : '';

        return $name !== '' && mb_strlen($name) <= 255 ? $name : null;
    }

    /** Отсутствующий или мусорный `active` считаем выключением: тумблер шлёт его всегда. */
    private function extractActive(Request $request): bool
    {
        $payload = json_decode($request->getContent(), true);

        return is_array($payload) && ($payload['active'] ?? false) === true;
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
        return [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'isActive' => $category->isActive(),
            'imageUrl' => $this->imageUrlGenerator->getImageUrl($category->getImageKey()),
            'items' => array_map(
                fn (PurchaseCategoryItem $item): array => [
                    'id' => $item->getId(),
                    'name' => $item->getName(),
                    // isActive — положение тумблера в справочнике, available — предлагать ли
                    // позицию в форме заявки. Разные вещи: выключенная категория гасит
                    // свои позиции, но их собственные тумблеры остаются включёнными.
                    'isActive' => $item->isActive(),
                    'available' => $item->isAvailable(),
                    'imageUrl' => $this->imageUrlGenerator->getImageUrl($item->getImageKey()),
                ],
                $category->getItems()->toArray(),
            ),
        ];
    }
}
