<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\Item;
use App\Entity\Inventory\ItemCategory;
use App\Entity\Inventory\ItemHistory;
use App\Entity\Inventory\Upd;
use App\Entity\Organization\AbstractOrganization;
use App\Entity\User\User;
use App\Enum\Inventory\ItemStatus;
use App\Repository\Inventory\ItemCategoryRepository;
use App\Repository\Inventory\ItemHistoryRepository;
use App\Repository\Inventory\ItemRepository;
use App\Repository\Inventory\UpdRepository;
use App\Repository\Organization\OrganizationRepository;
use App\Repository\User\UserRepository;
use App\Service\Inventory\InventoryAccessResolver;
use App\Service\Inventory\InventoryHistoryLogger;
use App\Service\Inventory\ItemFilterFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Товары инвентаризации.
 *
 * Редактирование карточки и операции над товаром намеренно разведены: у операций
 * разные права и каждая обязана оставить запись в истории.
 */
#[Route('/spa/api/inventory/items')]
#[IsGranted('ROLE_INVENTORY_MANAGER')]
final class ItemController extends AbstractController
{
    public function __construct(
        private readonly ItemRepository $itemRepository,
        private readonly ItemCategoryRepository $categoryRepository,
        private readonly ItemHistoryRepository $historyRepository,
        private readonly UpdRepository $updRepository,
        private readonly OrganizationRepository $organizationRepository,
        private readonly UserRepository $userRepository,
        private readonly InventoryAccessResolver $accessResolver,
        private readonly InventoryHistoryLogger $historyLogger,
        private readonly ItemFilterFactory $filterFactory,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'spa_api_inventory_items_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $scope = $this->accessResolver->resolveCurrent();
        if ($scope->isEmpty()) {
            return $this->json(['error' => SpaApiError::INVENTORY_NO_ACCESS], Response::HTTP_FORBIDDEN);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $pageSize = max(1, min(100, $request->query->getInt('page_size', 20)));

        $pagination = $this->itemRepository->findPaginated(
            $scope,
            $this->filterFactory->fromRequest($request),
            $page,
            $pageSize,
        );

        return $this->json([
            'items' => array_map(fn (Item $item): array => $this->format($item), $pagination['items']),
            'pagination' => [
                'current_page' => $pagination['page'],
                'total_pages' => $pagination['totalPages'],
                'total_items' => $pagination['total'],
                'items_per_page' => $pagination['limit'],
            ],
            'filters' => [
                'statuses' => array_map(
                    static fn (ItemStatus $status): array => [
                        'value' => $status->value,
                        'label' => $status->getLabel(),
                    ],
                    ItemStatus::cases(),
                ),
            ],
        ]);
    }

    #[Route('/{id}', name: 'spa_api_inventory_items_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        [$item, $error] = $this->resolveItem($id);
        if ($error !== null) {
            return $error;
        }

        return $this->json(['item' => $this->format($item)]);
    }

    #[Route('', name: 'spa_api_inventory_items_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $scope = $this->accessResolver->resolveCurrent();

        $organization = $this->organizationRepository->find((int) ($payload['organizationId'] ?? 0));
        if (!$organization instanceof AbstractOrganization) {
            return $this->json(['error' => SpaApiError::ORGANIZATION_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        // Категория необязательна: неразобранный предмет заводится без неё.
        // Ответственный за категорию при этом обязан её указать — товар без категории
        // не входит в его зону, и canSee ниже такую попытку отклонит.
        $category = null;
        $categoryId = (int) ($payload['categoryId'] ?? 0);
        if ($categoryId > 0) {
            $category = $this->categoryRepository->find($categoryId);
            if (!$category instanceof ItemCategory) {
                return $this->json(['error' => SpaApiError::INVENTORY_CATEGORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
            }

            if (!$category->isActive()) {
                return $this->json(['error' => SpaApiError::INVENTORY_CATEGORY_NOT_ALLOWED], Response::HTTP_CONFLICT);
            }
        }

        if (!$scope->canSee((int) $organization->getId(), $category !== null ? (int) $category->getId() : null)) {
            return $this->json(
                ['error' => SpaApiError::INVENTORY_ORGANIZATION_NOT_ALLOWED],
                Response::HTTP_FORBIDDEN,
            );
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => SpaApiError::INVENTORY_ITEM_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        // Номер необязателен: предмет со стёртой биркой заводится без него,
        // иначе люди вписывают «б/н» и упираются в уникальный индекс на втором таком.
        $inventoryNumber = trim((string) ($payload['inventoryNumber'] ?? ''));
        if ($inventoryNumber !== ''
            && $this->itemRepository->inventoryNumberExists((int) $organization->getId(), $inventoryNumber)
        ) {
            return $this->json(['error' => SpaApiError::INVENTORY_ITEM_NUMBER_EXISTS], Response::HTTP_CONFLICT);
        }

        $item = new Item();
        $item->setOrganization($organization);
        $item->setCategory($category);

        // Документ необязателен: первичная инвентаризация и старая мебель заводятся
        // без него. Проверки те же, что у операции привязки, — общий resolveUpdFor.
        $updId = (int) ($payload['updId'] ?? 0);
        if ($updId > 0) {
            [$upd, $updError] = $this->resolveUpdFor($updId, $organization);
            if ($updError !== null) {
                return $updError;
            }
            $item->setUpd($upd);
        }

        $item->setName(mb_substr($name, 0, 255));
        $item->setInventoryNumber($inventoryNumber === '' ? null : mb_substr($inventoryNumber, 0, 64));
        $item->setCreatedBy($currentUser);
        $item->setUpdatedBy($currentUser);

        $fieldError = $this->applyCardFields($item, $payload);
        if ($fieldError !== null) {
            return $this->json(['error' => $fieldError], Response::HTTP_BAD_REQUEST);
        }

        if (isset($payload['status'])) {
            $status = ItemStatus::tryFrom((string) $payload['status']);
            if ($status === null) {
                return $this->json(['error' => SpaApiError::INVENTORY_INVALID_STATUS], Response::HTTP_BAD_REQUEST);
            }
            $item->setStatus($status);
        }

        $this->em->persist($item);
        $this->historyLogger->logCreated($item);
        $this->em->flush();

        return $this->json(['item' => $this->format($item)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spa_api_inventory_items_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        [$item, $error] = $this->resolveItem($id);
        if ($error !== null) {
            return $error;
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('name', $payload)) {
            $name = trim((string) $payload['name']);
            if ($name === '') {
                return $this->json(['error' => SpaApiError::INVENTORY_ITEM_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
            }
            $item->setName(mb_substr($name, 0, 255));
        }

        if (array_key_exists('inventoryNumber', $payload)) {
            $inventoryNumber = trim((string) $payload['inventoryNumber']);

            if ($inventoryNumber === '') {
                $item->setInventoryNumber(null);
            } else {
                $taken = $this->itemRepository->inventoryNumberExists(
                    (int) $item->getOrganization()->getId(),
                    $inventoryNumber,
                    $item->getId(),
                );
                if ($taken) {
                    return $this->json(
                        ['error' => SpaApiError::INVENTORY_ITEM_NUMBER_EXISTS],
                        Response::HTTP_CONFLICT,
                    );
                }

                $item->setInventoryNumber(mb_substr($inventoryNumber, 0, 64));
            }
        }

        $fieldError = $this->applyCardFields($item, $payload);
        if ($fieldError !== null) {
            return $this->json(['error' => $fieldError], Response::HTTP_BAD_REQUEST);
        }

        $item->setUpdatedBy($currentUser instanceof User ? $currentUser : null);

        $this->em->flush();

        return $this->json(['item' => $this->format($item)]);
    }

    #[Route('/{id}/assign', name: 'spa_api_inventory_items_assign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function assign(int $id, Request $request, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        [$item, $error] = $this->resolveItem($id);
        if ($error !== null) {
            return $error;
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $assignee = $this->userRepository->find((int) ($payload['userId'] ?? 0));
        if (!$assignee instanceof User) {
            return $this->json(['error' => SpaApiError::USER_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isAssignable($assignee, $item->getOrganization())) {
            return $this->json(
                ['error' => SpaApiError::INVENTORY_USER_NOT_IN_ORGANIZATION],
                Response::HTTP_CONFLICT,
            );
        }

        $previous = $item->getAssignedTo();
        if ($previous?->getId() === $assignee->getId()) {
            return $this->json(['item' => $this->format($item)]);
        }

        $item->setAssignedTo($assignee);
        $item->setUpdatedBy($currentUser instanceof User ? $currentUser : null);
        $this->historyLogger->logAssignment($item, $previous, $assignee);
        $this->em->flush();

        return $this->json(['item' => $this->format($item)]);
    }

    #[Route('/{id}/unassign', name: 'spa_api_inventory_items_unassign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unassign(int $id, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        [$item, $error] = $this->resolveItem($id);
        if ($error !== null) {
            return $error;
        }

        $previous = $item->getAssignedTo();
        if ($previous === null) {
            return $this->json(['item' => $this->format($item)]);
        }

        $item->setAssignedTo(null);
        $item->setUpdatedBy($currentUser instanceof User ? $currentUser : null);
        $this->historyLogger->logAssignment($item, $previous, null);
        $this->em->flush();

        return $this->json(['item' => $this->format($item)]);
    }

    #[Route('/{id}/status', name: 'spa_api_inventory_items_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function changeStatus(int $id, Request $request, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        [$item, $error] = $this->resolveItem($id);
        if ($error !== null) {
            return $error;
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $status = ItemStatus::tryFrom((string) ($payload['status'] ?? ''));
        if ($status === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_STATUS], Response::HTTP_BAD_REQUEST);
        }

        $previous = $item->getStatus();
        if ($previous === $status) {
            return $this->json(['item' => $this->format($item)]);
        }

        $item->setStatus($status);
        $item->setUpdatedBy($currentUser instanceof User ? $currentUser : null);
        $this->historyLogger->logStatusChanged($item, $previous, $status);
        $this->em->flush();

        return $this->json(['item' => $this->format($item)]);
    }

    /**
     * Перенос в другую организацию — только админу организации, и он должен быть
     * админом по обе стороны переноса.
     */
    #[Route('/{id}/move', name: 'spa_api_inventory_items_move', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function move(int $id, Request $request, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        [$item, $error] = $this->resolveItem($id);
        if ($error !== null) {
            return $error;
        }

        $scope = $this->accessResolver->resolveCurrent();
        if (!$scope->isOrganizationAdmin((int) $item->getOrganization()->getId())) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $target = $this->organizationRepository->find((int) ($payload['organizationId'] ?? 0));
        if (!$target instanceof AbstractOrganization) {
            return $this->json(['error' => SpaApiError::ORGANIZATION_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$scope->isOrganizationAdmin((int) $target->getId())) {
            return $this->json(
                ['error' => SpaApiError::INVENTORY_ORGANIZATION_NOT_ALLOWED],
                Response::HTTP_FORBIDDEN,
            );
        }

        $source = $item->getOrganization();
        if ($source->getId() === $target->getId()) {
            return $this->json(['item' => $this->format($item)]);
        }

        // Владелец из старой ветки в новой организации уже не валиден — пусть админ
        // сначала снимет товар, чтобы перенос не менял владельца молча.
        $assignee = $item->getAssignedTo();
        if ($assignee !== null && !$this->isAssignable($assignee, $target)) {
            return $this->json(
                ['error' => SpaApiError::INVENTORY_USER_NOT_IN_ORGANIZATION],
                Response::HTTP_CONFLICT,
            );
        }

        $inventoryNumber = $item->getInventoryNumber();
        if ($inventoryNumber !== null
            && $this->itemRepository->inventoryNumberExists((int) $target->getId(), $inventoryNumber, $item->getId())
        ) {
            return $this->json(['error' => SpaApiError::INVENTORY_ITEM_NUMBER_EXISTS], Response::HTTP_CONFLICT);
        }

        $item->setOrganization($target);
        $item->setUpdatedBy($currentUser instanceof User ? $currentUser : null);
        $this->historyLogger->logMoved($item, $source->getName(), $target->getName());
        $this->em->flush();

        return $this->json(['item' => $this->format($item)]);
    }

    /**
     * Смена категории — только админу организации: ответственный за категорию иначе
     * уводил бы товары из своей зоны или забирал чужие.
     */
    #[Route('/{id}/category', name: 'spa_api_inventory_items_category', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function changeCategory(int $id, Request $request, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        [$item, $error] = $this->resolveItem($id);
        if ($error !== null) {
            return $error;
        }

        $scope = $this->accessResolver->resolveCurrent();
        if (!$scope->isOrganizationAdmin((int) $item->getOrganization()->getId())) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        // categoryId = null или 0 снимает категорию, возвращая товар в неразобранные.
        $category = null;
        $categoryId = (int) ($payload['categoryId'] ?? 0);
        if ($categoryId > 0) {
            $category = $this->categoryRepository->find($categoryId);
            if (!$category instanceof ItemCategory) {
                return $this->json(['error' => SpaApiError::INVENTORY_CATEGORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
            }
        }

        $previous = $item->getCategory();
        if ($previous?->getId() === $category?->getId()) {
            return $this->json(['item' => $this->format($item)]);
        }

        if ($category !== null && !$category->isActive()) {
            return $this->json(['error' => SpaApiError::INVENTORY_CATEGORY_NOT_ALLOWED], Response::HTTP_CONFLICT);
        }

        $item->setCategory($category);
        $item->setUpdatedBy($currentUser instanceof User ? $currentUser : null);
        $this->historyLogger->logCategoryChanged(
            $item,
            $previous?->getName() ?? 'без категории',
            $category?->getName() ?? 'без категории',
        );
        $this->em->flush();

        return $this->json(['item' => $this->format($item)]);
    }

    /**
     * Привязка к документу поступления. Как перенос и смена категории — только админу
     * организации: ответственный за категорию документов вообще не видит, и выбрать
     * ему было бы не из чего.
     */
    #[Route('/{id}/upd', name: 'spa_api_inventory_items_upd', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function changeUpd(int $id, Request $request, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        [$item, $error] = $this->resolveItem($id);
        if ($error !== null) {
            return $error;
        }

        $scope = $this->accessResolver->resolveCurrent();
        if (!$scope->isOrganizationAdmin((int) $item->getOrganization()->getId())) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        // updId = null или 0 отвязывает документ, позиция при этом остаётся.
        $upd = null;
        $updId = (int) ($payload['updId'] ?? 0);
        if ($updId > 0) {
            [$upd, $updError] = $this->resolveUpdFor($updId, $item->getOrganization());
            if ($updError !== null) {
                return $updError;
            }
        }

        $previous = $item->getUpd();
        if ($previous?->getId() === $upd?->getId()) {
            return $this->json(['item' => $this->format($item)]);
        }

        $item->setUpd($upd);
        $item->setUpdatedBy($currentUser instanceof User ? $currentUser : null);
        $this->historyLogger->logUpdChanged(
            $item,
            $previous?->getLabel() ?? 'без документа',
            $upd?->getLabel() ?? 'без документа',
        );
        $this->em->flush();

        return $this->json(['item' => $this->format($item)]);
    }

    #[Route('/{id}/history', name: 'spa_api_inventory_items_history', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function history(int $id): JsonResponse
    {
        [$item, $error] = $this->resolveItem($id);
        if ($error !== null) {
            return $error;
        }

        return $this->json([
            'history' => array_map(
                fn (ItemHistory $history): array => $this->formatHistory($history),
                $this->historyRepository->findByItem($item),
            ),
        ]);
    }

    /**
     * @return array{0: Item|null, 1: JsonResponse|null}
     */
    private function resolveItem(int $id): array
    {
        $item = $this->itemRepository->find($id);
        if (!$item instanceof Item) {
            return [null, $this->json(['error' => SpaApiError::INVENTORY_ITEM_NOT_FOUND], Response::HTTP_NOT_FOUND)];
        }

        if (!$this->accessResolver->resolveCurrent()->canSeeItem($item)) {
            return [null, $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN)];
        }

        return [$item, null];
    }

    /**
     * Назначать можно только на сотрудника организации товара или её потомков:
     * иначе учёт по департаментам разваливается молча.
     */
    private function isAssignable(User $assignee, AbstractOrganization $organization): bool
    {
        return $this->isInSubtree($organization, $assignee->getOrganization());
    }

    /**
     * Документ для позиции — общая проверка для заведения и для операции привязки.
     *
     * Видимость документа проверяется отдельно от видимости позиции: иначе, подобрав
     * id, можно было бы привязаться к чужому УПД и вычитать его номер с датой
     * из карточки собственной позиции.
     *
     * @return array{0: Upd|null, 1: JsonResponse|null}
     */
    private function resolveUpdFor(int $updId, AbstractOrganization $itemOrganization): array
    {
        $upd = $this->updRepository->find($updId);
        if (!$upd instanceof Upd) {
            return [null, $this->json(['error' => SpaApiError::INVENTORY_UPD_NOT_FOUND], Response::HTTP_NOT_FOUND)];
        }

        if (!$this->accessResolver->resolveCurrent()->isOrganizationAdmin((int) $upd->getOrganization()->getId())) {
            return [null, $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN)];
        }

        // Позиция обязана числиться за организацией документа или её потомком, иначе
        // по документу для одного филиала заводилось бы имущество другого.
        if (!$this->isInSubtree($upd->getOrganization(), $itemOrganization)) {
            return [null, $this->json(
                ['error' => SpaApiError::INVENTORY_UPD_ORGANIZATION_MISMATCH],
                Response::HTTP_CONFLICT,
            )];
        }

        return [$upd, null];
    }

    /**
     * Входит ли организация в поддерево корневой. Тем же правилом проверяются
     * владелец при назначении и позиция при привязке к документу.
     */
    private function isInSubtree(AbstractOrganization $root, ?AbstractOrganization $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }

        $allowed = $this->organizationRepository->findOrganizationWithChildrenIds((int) $root->getId());

        return in_array((int) $candidate->getId(), $allowed, true);
    }

    /**
     * Необязательные поля карточки. Пустое значение очищает поле, мусор — ошибка:
     * молча обнулять цену или дату из-за опечатки нельзя, это тихая потеря данных.
     *
     * @return string|null код ошибки из SpaApiError, если значение не разобрано
     */
    private function applyCardFields(Item $item, array $payload): ?string
    {
        if (array_key_exists('serialNumber', $payload)) {
            $serialNumber = trim((string) $payload['serialNumber']);
            $item->setSerialNumber($serialNumber === '' ? null : mb_substr($serialNumber, 0, 128));
        }

        if (array_key_exists('description', $payload)) {
            $description = trim((string) $payload['description']);
            $item->setDescription($description === '' ? null : $description);
        }

        if (array_key_exists('price', $payload)) {
            $price = $payload['price'];
            if ($price === null || $price === '') {
                $item->setPrice(null);
            } elseif (is_numeric($price)) {
                $item->setPrice(number_format((float) $price, 2, '.', ''));
            } else {
                return SpaApiError::INVENTORY_INVALID_PRICE;
            }
        }

        if (array_key_exists('purchasedAt', $payload)) {
            $purchasedAt = $payload['purchasedAt'];
            if ($purchasedAt === null || $purchasedAt === '') {
                $item->setPurchasedAt(null);
            } else {
                // Сверяем обратным форматированием: createFromFormat возвращает false
                // только на структурном мусоре, а несуществующую дату молча переполняет —
                // «2026-02-31» уехало бы в 3 марта, и в карточке оказалась бы не та дата,
                // которую ввели. Заодно отсекает незаполненные нулями «2026-2-3».
                $raw = trim((string) $purchasedAt);
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
                if ($parsed === false || $parsed->format('Y-m-d') !== $raw) {
                    return SpaApiError::INVENTORY_INVALID_DATE;
                }
                $item->setPurchasedAt($parsed);
            }
        }

        return null;
    }

    private function format(Item $item): array
    {
        $organization = $item->getOrganization();
        $category = $item->getCategory();
        $upd = $item->getUpd();

        return [
            // Компактная ссылка без файлов — её видит любой, кто видит позицию,
            // включая ответственного за категорию, которому сам документ закрыт.
            // Иначе у него в карточке зияла бы дыра там, где у остальных документ.
            'upd' => $upd !== null
                ? [
                    'id' => $upd->getId(),
                    'number' => $upd->getNumber(),
                    'date' => $upd->getDate()->format('Y-m-d'),
                    'label' => $upd->getLabel(),
                ]
                : null,
            'id' => $item->getId(),
            'name' => $item->getName(),
            'inventoryNumber' => $item->getInventoryNumber(),
            'serialNumber' => $item->getSerialNumber(),
            'category' => $category !== null
                ? ['id' => $category->getId(), 'name' => $category->getName()]
                : null,
            'organization' => [
                'id' => $organization->getId(),
                'name' => $organization->getName(),
                'fullName' => $organization->getFullName(),
                'path' => $organization->getPath(),
            ],
            'assignedTo' => $this->formatUser($item->getAssignedTo()),
            'status' => $item->getStatus()->value,
            'statusLabel' => $item->getStatus()->getLabel(),
            'description' => $item->getDescription(),
            'price' => $item->getPrice(),
            'purchasedAt' => $item->getPurchasedAt()?->format('Y-m-d'),
            'createdAt' => $item->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $item->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function formatHistory(ItemHistory $history): array
    {
        return [
            'id' => $history->getId(),
            'action' => $history->getAction()->value,
            'actionLabel' => $history->getAction()->getLabel(),
            'oldStatus' => $history->getOldStatus()?->value,
            'oldStatusLabel' => $history->getOldStatus()?->getLabel(),
            'newStatus' => $history->getNewStatus()?->value,
            'newStatusLabel' => $history->getNewStatus()?->getLabel(),
            'oldAssignedTo' => $this->formatUser($history->getOldAssignedTo()),
            'newAssignedTo' => $this->formatUser($history->getNewAssignedTo()),
            'comment' => $history->getComment(),
            'changedBy' => $this->formatUser($history->getChangedBy()),
            'createdAt' => $history->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function formatUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->getId(),
            'lastname' => $user->getLastname() ?? '-',
            'firstname' => $user->getFirstname() ?? '-',
            'patronymic' => $user->getPatronymic() ?? '-',
            'login' => $user->getLogin(),
        ];
    }
}
