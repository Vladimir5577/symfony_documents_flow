<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseCategoryItem;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestItem;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseLaw;
use App\Enum\Purchase\PurchaseMethod;
use App\Enum\Purchase\PurchaseSettingKey;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\User\UserRole;
use App\Repository\Purchase\PurchaseCategoryRepository;
use App\Repository\Purchase\PurchaseRequestApproverRepository;
use App\Repository\Purchase\PurchaseRequestRepository;
use App\Repository\Purchase\PurchaseSettingRepository;
use App\Service\Purchase\PurchaseApiPresenter;
use App\Service\Purchase\PurchaseFileStorageService;
use App\Service\Purchase\PurchaseRequestService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/purchases')]
final class PurchaseController extends AbstractController
{
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE = 100;
    private const MAX_ITEMS_PER_REQUEST = 100;

    public function __construct(
        private readonly PurchaseRequestRepository $purchaseRepo,
        private readonly PurchaseCategoryRepository $categoryRepo,
        private readonly PurchaseRequestApproverRepository $approverRepo,
        private readonly PurchaseSettingRepository $settingRepo,
        private readonly PurchaseApiPresenter $presenter,
        private readonly PurchaseRequestService $purchaseService,
        private readonly PurchaseFileStorageService $fileStorage,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'spa_api_purchases_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // Режим «я согласант»: только заявки, куда пользователя пригласили (доступен любой роли)
        $asApprover = $request->query->getBoolean('as_approver');
        if ($asApprover) {
            $scope = [null, null];
        } else {
            $scope = $this->resolveScope($user);
            if ($scope === null) {
                return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
            }
        }

        [$createdById, $visibleStatuses] = $scope;

        $statuses = $visibleStatuses;
        // Мультивыбор чекбоксами: statuses=A,B,C. Приоритетнее одиночного status (вместе не шлются).
        $statusesFilter = trim((string) $request->query->get('statuses', ''));
        $statusFilter = trim((string) $request->query->get('status', ''));
        if ($statusesFilter !== '') {
            $requested = [];
            foreach (explode(',', $statusesFilter) as $rawStatus) {
                $parsed = PurchaseStatus::tryFrom(trim($rawStatus));
                if ($parsed === null) {
                    return $this->json(['error' => SpaApiError::PURCHASE_INVALID_STATUS], Response::HTTP_BAD_REQUEST);
                }
                $requested[] = $parsed;
            }
            // Пересечение со скоупом роли: чекбоксы не открывают чужие статусы.
            // Не array_intersect — он сравнивает через (string), а enum к строке не приводится.
            $statuses = $visibleStatuses === null
                ? $requested
                : array_values(array_filter(
                    $requested,
                    static fn (PurchaseStatus $s): bool => in_array($s, $visibleStatuses, true),
                ));
        } elseif ($statusFilter !== '') {
            $requested = PurchaseStatus::tryFrom($statusFilter);
            if ($requested === null) {
                return $this->json(['error' => SpaApiError::PURCHASE_INVALID_STATUS], Response::HTTP_BAD_REQUEST);
            }
            if ($visibleStatuses !== null && !in_array($requested, $visibleStatuses, true)) {
                $statuses = [];
            } else {
                $statuses = [$requested];
            }
        }

        // Порог суммы: скрыть мелочёвку. Не-числа молча игнорируются.
        $minAmountParam = $request->query->get('min_amount');
        $minAmount = is_numeric($minAmountParam) && (float) $minAmountParam > 0 ? (float) $minAmountParam : null;

        $page = max(1, $request->query->getInt('page', 1));
        $pageSize = min(self::MAX_PAGE_SIZE, max(1, $request->query->getInt('page_size', self::DEFAULT_PAGE_SIZE)));
        $search = trim((string) $request->query->get('search', ''));

        $result = $this->purchaseRepo->findByFilters(
            $createdById,
            $statuses,
            $search !== '' ? $search : null,
            $page,
            $pageSize,
            $asApprover ? (int) $user->getId() : null,
            $minAmount,
        );

        // Количество позиций и сумму берём одним запросом на всю страницу:
        // иначе презентер загрузил бы коллекцию позиций у каждой заявки.
        $itemAggregates = $this->purchaseRepo->sumAndCountItemsByRequestIds(
            array_values(array_filter(array_map(
                static fn (PurchaseRequest $item): ?int => $item->getId(),
                $result['items'],
            ))),
        );

        return $this->json([
            'items' => array_map(
                fn (PurchaseRequest $item): array => $this->presenter->presentListItem(
                    $item,
                    // Заявка без позиций в агрегат не попадает: подставляем
                    // нули явно, иначе презентер уйдёт в ленивую загрузку
                    // пустой коллекции — ровно тот запрос, который мы и убирали.
                    $itemAggregates[$item->getId()] ?? ['count' => 0, 'total' => 0.0],
                ),
                $result['items'],
            ),
            'pagination' => [
                'current_page' => $page,
                'items_per_page' => $pageSize,
                'total_items' => $result['total'],
                'total_pages' => (int) ceil($result['total'] / $pageSize),
            ],
        ]);
    }

    /**
     * Счётчики для бейджей: сколько заявок требует действия текущей роли.
     */
    #[Route('/counters', name: 'spa_api_purchases_counters', methods: ['GET'])]
    public function counters(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $approverPending = $this->approverRepo->countPendingForUser($user);

        $scope = $this->resolveScope($user);
        if ($scope === null) {
            return $this->json([
                'byStatus' => new \stdClass(),
                'actionRequired' => $approverPending,
                'approverPending' => $approverPending,
            ]);
        }

        [$createdById] = $scope;
        $byStatus = $this->purchaseRepo->countByStatuses($createdById);

        $actionRequired = $approverPending;
        if ($this->isGranted(UserRole::ROLE_PURCHASE_DIRECTOR->value)) {
            $actionRequired += $byStatus[PurchaseStatus::CEO_APPROVE_PENDING->value] ?? 0;
        }
        if ($this->isGranted(UserRole::ROLE_PURCHASE_DEPARTMENT->value)) {
            $actionRequired += ($byStatus[PurchaseStatus::NEW->value] ?? 0)
                + ($byStatus[PurchaseStatus::APPROVERS_DONE->value] ?? 0)
                + ($byStatus[PurchaseStatus::CEO_APPROVED->value] ?? 0)
                + ($byStatus[PurchaseStatus::CONTRACT_PENDING->value] ?? 0);
        }
        if ($this->isGranted(UserRole::ROLE_MANAGER->value)) {
            $actionRequired += ($byStatus[PurchaseStatus::REJECTED->value] ?? 0)
                + ($byStatus[PurchaseStatus::DELIVERED->value] ?? 0);
        }

        return $this->json([
            'byStatus' => $byStatus === [] ? new \stdClass() : $byStatus,
            'actionRequired' => $actionRequired,
            'approverPending' => $approverPending,
        ]);
    }

    /**
     * Настройки модуля. Читают все авторизованные — порог влияет на маршрут заявки,
     * и фронт показывает его в подсказках.
     *
     * Объявлены до /{id}: иначе «settings» уйдёт в маршрут карточки.
     */
    #[Route('/settings', name: 'spa_api_purchases_settings_get', methods: ['GET'])]
    public function getSettings(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->json([
            'ceoApproveMinAmount' => $this->settingRepo->getCeoApproveMinAmount(),
        ]);
    }

    /** Меняют порог только директор и админ портала. */
    #[Route('/settings', name: 'spa_api_purchases_settings_update', methods: ['PUT'])]
    public function updateSettings(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isGranted(UserRole::ROLE_PURCHASE_DIRECTOR->value) && !$this->isGranted(UserRole::ROLE_ADMIN->value)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !array_key_exists('ceoApproveMinAmount', $payload)) {
            return $this->json(['error' => SpaApiError::PURCHASE_INVALID_SETTING], Response::HTTP_BAD_REQUEST);
        }

        // Тип значения БД не контролирует (JSON) — проверяем по ключу
        $key = PurchaseSettingKey::CEO_APPROVE_MIN_AMOUNT;
        if (!$key->isValid($payload['ceoApproveMinAmount'])) {
            return $this->json(['error' => SpaApiError::PURCHASE_INVALID_SETTING], Response::HTTP_BAD_REQUEST);
        }

        $this->settingRepo->set($key, (float) $payload['ceoApproveMinAmount']);
        $this->em->flush();

        return $this->json([
            'ceoApproveMinAmount' => $this->settingRepo->getCeoApproveMinAmount(),
        ]);
    }

    #[Route('', name: 'spa_api_purchases_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // Создать заявку может любой авторизованный пользователь.
        $organization = $user->getOrganization();
        if ($organization === null) {
            return $this->json(['error' => SpaApiError::ORGANIZATION_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $purchase = new PurchaseRequest();
        $purchase->setOrganization($organization);
        $purchase->setCreatedBy($user);

        $error = $this->applyPayload($purchase, $payload);
        if ($error !== null) {
            return $error;
        }

        $this->em->persist($purchase);
        $this->purchaseService->logCreated($purchase, $user);
        $this->em->flush();

        return $this->json($this->presenter->presentDetail($purchase), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spa_api_purchases_get', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function get(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->canView($purchase, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->presenter->presentDetail($purchase));
    }

    /**
     * Редактирование заявки (только DRAFT/REJECTED). Позиции заменяются целиком.
     */
    #[Route('/{id}', name: 'spa_api_purchases_update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->canEdit($purchase, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $error = $this->applyPayload($purchase, $payload);
        if ($error !== null) {
            return $error;
        }

        $this->em->flush();

        return $this->json($this->presenter->presentDetail($purchase));
    }

    /**
     * Физическое удаление черновика.
     */
    #[Route('/{id}', name: 'spa_api_purchases_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        // Удалить черновик может только его автор
        if (!($this->isManagerOwner($purchase, $user) && $purchase->getStatus() === PurchaseStatus::DRAFT)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        // Строки вложений уедут сами (orphanRemoval), а объекты в бакете — нет:
        // ключи хранятся только в этих строках, после удаления их не найти.
        $this->fileStorage->deleteAllFor($purchase);

        $this->em->remove($purchase);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Категория / закон / способ закупки. Отдел закупок проставляет то,
     * что менеджер не заполнил (или правит) на этапе рассмотрения.
     * Отсутствующий в payload ключ не трогаем, null — очистить.
     */
    #[Route('/{id}/classification', name: 'spa_api_purchases_classification', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function classification(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        // Классификацию правит отдел закупок на рассмотрении и согласовании
        if (!($this->isGranted(UserRole::ROLE_PURCHASE_DEPARTMENT->value)
            && in_array($purchase->getStatus(), [PurchaseStatus::NEW, PurchaseStatus::APPROVERS_PENDING], true))) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $error = $this->applyClassification($purchase, $payload);
        if ($error !== null) {
            return $error;
        }

        $this->em->flush();

        return $this->json($this->presenter->presentDetail($purchase));
    }

    /**
     * Область видимости списка для пользователя:
     * [createdById|null, visibleStatuses|null].
     * Директор — со своего этапа; отдел закупок — с подачи;
     * плательщик — очередь на оплату; остальные — только свои заявки.
     *
     * @return array{0: int|null, 1: list<PurchaseStatus>|null}|null
     */
    private function resolveScope(User $user): ?array
    {
        if ($this->isGranted(UserRole::ROLE_PURCHASE_DIRECTOR->value)) {
            return [null, PurchaseStatus::getDirectorVisible()];
        }

        if ($this->isGranted(UserRole::ROLE_PURCHASE_DEPARTMENT->value)) {
            return [null, PurchaseStatus::getPurchaseDepartmentVisible()];
        }

        if ($this->isGranted(UserRole::ROLE_PURCHASE_INVOICE->value)) {
            return [null, PurchaseStatus::getPayerVisible()];
        }

        // Любой авторизованный пользователь видит свои заявки.
        return [(int) $user->getId(), null];
    }

    /** Автор заявки — роль не требуется: создавать может любой пользователь. */
    private function isManagerOwner(PurchaseRequest $purchase, User $user): bool
    {
        return $purchase->getCreatedBy()?->getId() === $user->getId();
    }

    /** Кто видит заявку: директор — со своего этапа; отдел закупок — с подачи; автор и приглашённый согласант — свою. */
    private function canView(PurchaseRequest $purchase, User $user): bool
    {
        if ($this->isGranted(UserRole::ROLE_PURCHASE_DIRECTOR->value)
            && in_array($purchase->getStatus(), PurchaseStatus::getDirectorVisible(), true)
        ) {
            return true;
        }
        if ($this->isGranted(UserRole::ROLE_PURCHASE_DEPARTMENT->value)
            && in_array($purchase->getStatus(), PurchaseStatus::getPurchaseDepartmentVisible(), true)
        ) {
            return true;
        }
        if ($this->isGranted(UserRole::ROLE_PURCHASE_INVOICE->value)
            && in_array($purchase->getStatus(), PurchaseStatus::getPayerVisible(), true)
        ) {
            return true;
        }
        if ($this->isManagerOwner($purchase, $user)) {
            return true;
        }

        return $purchase->findApproverFor($user) !== null;
    }

    /** Править заявку может только автор и только в редактируемом статусе (DRAFT/REJECTED). */
    private function canEdit(PurchaseRequest $purchase, User $user): bool
    {
        return $this->isManagerOwner($purchase, $user) && $purchase->getStatus()->isEditable();
    }

    /**
     * Общие поля create/update: title, description, dueDate, items (целиком).
     */
    private function applyPayload(PurchaseRequest $purchase, array $payload): ?JsonResponse
    {
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            return $this->json(['error' => SpaApiError::PURCHASE_TITLE_REQUIRED], Response::HTTP_BAD_REQUEST);
        }
        $purchase->setTitle($title);

        $description = $payload['description'] ?? null;
        $purchase->setDescription(is_string($description) && trim($description) !== '' ? trim($description) : null);

        $justification = $payload['justification'] ?? null;
        $purchase->setJustification(is_string($justification) && trim($justification) !== '' ? trim($justification) : null);

        $technicalSpec = $payload['technicalSpec'] ?? null;
        $purchase->setTechnicalSpec(is_string($technicalSpec) && trim($technicalSpec) !== '' ? trim($technicalSpec) : null);

        $error = $this->applyClassification($purchase, $payload);
        if ($error !== null) {
            return $error;
        }

        $dueDateRaw = $payload['dueDate'] ?? null;
        if ($dueDateRaw === null || $dueDateRaw === '') {
            $purchase->setDueDate(null);
        } else {
            $dueDate = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $dueDateRaw);
            if ($dueDate === false) {
                return $this->json(['error' => SpaApiError::PURCHASE_INVALID_DUE_DATE], Response::HTTP_BAD_REQUEST);
            }
            $purchase->setDueDate($dueDate->setTime(0, 0));
        }

        $itemsPayload = $payload['items'] ?? [];
        if (!is_array($itemsPayload) || count($itemsPayload) > self::MAX_ITEMS_PER_REQUEST) {
            return $this->json(['error' => SpaApiError::PURCHASE_INVALID_ITEM], Response::HTTP_BAD_REQUEST);
        }

        $purchase->getItems()->clear();

        $position = 0;
        foreach ($itemsPayload as $itemPayload) {
            if (!is_array($itemPayload)) {
                return $this->json(['error' => SpaApiError::PURCHASE_INVALID_ITEM], Response::HTTP_BAD_REQUEST);
            }

            $name = trim((string) ($itemPayload['name'] ?? ''));
            $quantity = $itemPayload['quantity'] ?? null;
            $unit = trim((string) ($itemPayload['unit'] ?? ''));
            $price = $itemPayload['estimatedPrice'] ?? null;

            if ($name === '' || $unit === ''
                || !is_numeric($quantity) || (float) $quantity <= 0
                || !is_numeric($price) || (float) $price < 0
            ) {
                return $this->json(['error' => SpaApiError::PURCHASE_INVALID_ITEM], Response::HTTP_BAD_REQUEST);
            }

            $item = new PurchaseRequestItem();
            $item->setName($name);
            $item->setQuantity(number_format((float) $quantity, 3, '.', ''));
            $item->setUnit($unit);
            $item->setEstimatedPrice(number_format((float) $price, 2, '.', ''));
            $item->setPosition($position++);

            // Ссылка на номенклатуру: только у позиций, добавленных подбором из категории.
            $categoryItemId = $itemPayload['categoryItemId'] ?? null;
            if ($categoryItemId !== null && $categoryItemId !== '') {
                $categoryItem = $this->em->find(PurchaseCategoryItem::class, (int) $categoryItemId);
                if ($categoryItem === null) {
                    return $this->json(['error' => SpaApiError::PURCHASE_INVALID_ITEM], Response::HTTP_BAD_REQUEST);
                }
                $item->setCategoryItem($categoryItem);
            }

            $purchase->addItem($item);
        }

        return null;
    }

    /**
     * Общая часть applyPayload и PATCH classification:
     * categoryId, law, method — все ключи опциональны, null очищает поле.
     */
    private function applyClassification(PurchaseRequest $purchase, array $payload): ?JsonResponse
    {
        if (array_key_exists('categoryId', $payload)) {
            $categoryId = $payload['categoryId'];
            if ($categoryId === null || $categoryId === '') {
                $purchase->setCategory(null);
            } else {
                $category = $this->categoryRepo->find((int) $categoryId);
                if ($category === null) {
                    return $this->json(['error' => SpaApiError::PURCHASE_CATEGORY_NOT_FOUND], Response::HTTP_BAD_REQUEST);
                }
                $purchase->setCategory($category);
            }
        }

        if (array_key_exists('law', $payload)) {
            $lawRaw = $payload['law'];
            if ($lawRaw === null || $lawRaw === '') {
                $purchase->setLaw(null);
            } else {
                $law = PurchaseLaw::tryFrom((string) $lawRaw);
                if ($law === null) {
                    return $this->json(['error' => SpaApiError::PURCHASE_INVALID_LAW], Response::HTTP_BAD_REQUEST);
                }
                $purchase->setLaw($law);
            }
        }

        if (array_key_exists('method', $payload)) {
            $methodRaw = $payload['method'];
            if ($methodRaw === null || $methodRaw === '') {
                $purchase->setMethod(null);
            } else {
                $method = PurchaseMethod::tryFrom((string) $methodRaw);
                if ($method === null) {
                    return $this->json(['error' => SpaApiError::PURCHASE_INVALID_METHOD], Response::HTTP_BAD_REQUEST);
                }
                $purchase->setMethod($method);
            }
        }

        return null;
    }
}
