<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseCategoryItem;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestItem;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseCapability;
use App\Enum\Purchase\PurchaseHistoryAction;
use App\Enum\Purchase\PurchaseLaw;
use App\Enum\Purchase\PurchaseMethod;
use App\Enum\Purchase\PurchaseRequestKind;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\Purchase\PurchaseStagePurpose;
use App\Enum\User\UserRole;
use App\Repository\Purchase\PurchaseApprovalTaskRepository;
use App\Repository\Purchase\PurchaseCategoryRepository;
use App\Repository\Purchase\PurchaseRequestRepository;
use App\Repository\Purchase\PurchaseRouteDefaultRepository;
use App\Repository\Purchase\PurchaseRouteTemplateRepository;
use App\Service\Purchase\ApprovalRouteBuilder;
use App\Service\Purchase\PurchaseAccess;
use App\Service\Purchase\PurchaseApiPresenter;
use App\Service\Purchase\PurchaseFileStorageService;
use App\Service\Purchase\PurchaseApprovalWorkflow;
use App\Service\Purchase\PurchaseRequestEditor;
use App\Service\Purchase\PurchaseRoster;
use App\Service\Purchase\PurchaseTransitionException;
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
        private readonly PurchaseApprovalTaskRepository $taskRepo,
        private readonly PurchaseApiPresenter $presenter,
        private readonly PurchaseApprovalWorkflow $workflow,
        private readonly PurchaseRequestEditor $editor,
        private readonly PurchaseFileStorageService $fileStorage,
        private readonly EntityManagerInterface $em,
        private readonly PurchaseAccess $access,
        private readonly PurchaseRoster $roster,
        private readonly ApprovalRouteBuilder $routeBuilder,
        private readonly PurchaseRouteDefaultRepository $routeDefaults,
        private readonly PurchaseRouteTemplateRepository $templateRepo,
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
        [$createdById, $visibleStatuses] = $asApprover
            ? [null, null]
            : $this->resolveScope($user);

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
            $asApprover ? $this->roster->roleCodesOf($user) : [],
        );

        return $this->json([
            'items' => array_map(
                fn (PurchaseRequest $item): array => $this->presenter->presentListItem($item),
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

        // Согласование — активные шаги, ждущие лично меня или мою роль.
        // Именно активные: будущие шаги мне ещё недоступны, и бейдж показал бы
        // работу, которую сделать нельзя.
        $approverPending = $this->taskRepo->countActiveForUser($user, $this->roster->roleCodesOf($user));

        [$createdById] = $this->resolveScope($user);
        $byStatus = $this->purchaseRepo->countByStatuses($createdById);

        // Шагами маршрута счётчик не исчерпывается: часть работы живёт в конвейере
        // и шагом не является. Общее правило — «следующее действие доступно мне».
        $actionRequired = $approverPending;
        if ($this->access->can($user, PurchaseCapability::RUN_EXECUTION)) {
            // APPROVED — оплатить, DELIVERED — приложить УПД и убрать в архив
            $actionRequired += ($byStatus[PurchaseStatus::APPROVED->value] ?? 0)
                + ($byStatus[PurchaseStatus::DELIVERED->value] ?? 0);
        }
        if ($createdById !== null) {
            // Счётчики автора: вернули на доработку и оплаченное — ждём
            // подтверждения доставки. Проверка роли здесь не нужна: у автора
            // область видимости и так сужена до собственных заявок.
            $actionRequired += ($byStatus[PurchaseStatus::REJECTED->value] ?? 0)
                + ($byStatus[PurchaseStatus::INVOICE_PAID->value] ?? 0);
        }

        return $this->json([
            'byStatus' => $byStatus === [] ? new \stdClass() : $byStatus,
            'actionRequired' => $actionRequired,
            'approverPending' => $approverPending,
        ]);
    }

    /**
     * Моё место в модуле: какие роли выданы и что они позволяют.
     *
     * Нужен фронту вместо прежних ROLE_PURCHASE_* в JWT: роли модуля живут в
     * своей таблице, в токене их нет, и без этого ответа SPA не знает, кому
     * показывать справочники и вид «вижу все заявки».
     *
     * Полномочия считаем тем же PurchaseAccess, что и гейты, — вместе с
     * обходом для ROLE_ADMIN. Иначе у админа кнопки пропали бы, хотя запрос
     * за ними сервер бы пропустил.
     *
     * Объявлен до /{id}: иначе «my-access» уйдёт в маршрут карточки.
     */
    #[Route('/my-access', name: 'spa_api_purchases_my_access', methods: ['GET'])]
    public function myAccess(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $capabilities = array_values(array_filter(
            PurchaseCapability::cases(),
            fn (PurchaseCapability $capability): bool => $this->access->can($user, $capability),
        ));

        return $this->json([
            // Состав участников и выдачу ролей ведёт админ — это выдача прав
            'isAdmin' => $this->isGranted(UserRole::ROLE_ADMIN->value),
            'capabilities' => array_map(
                static fn (PurchaseCapability $capability): string => $capability->value,
                $capabilities,
            ),
            'roles' => array_map(
                static fn (PurchaseRoleCode $code): array => [
                    'code' => $code->value,
                    'name' => $code->getLabel(),
                ],
                $this->roster->rolesOf($user),
            ),
        ]);
    }

    /**
     * Очередь разбора: заявки, ждущие решения этого человека, по порядку.
     *
     * Ролевого гейта нет — очередь сама и есть ответ: у того, к кому задачи
     * разбора не адресованы, она пустая. Раньше гейт спрашивал «ты директор»,
     * и второй разбирающий в маршруте потребовал бы правки контроллера.
     *
     * Отдаётся карточками целиком, а не списком id: модалка показывает позиции
     * и обоснование, и догружать их по одной — лишний круг на каждую заявку.
     *
     * Объявлен до /{id}: иначе «director-queue» уйдёт в маршрут карточки.
     */
    #[Route('/director-queue', name: 'spa_api_purchases_director_queue', methods: ['GET'])]
    public function directorQueue(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $queue = $this->purchaseRepo->findTriageQueueFor($user, $this->roster->roleCodesOf($user));

        return $this->json([
            'items' => array_map(
                fn (PurchaseRequest $purchase): array => $this->presenter->presentDetail($purchase),
                $queue,
            ),
            'total' => count($queue),
        ]);
    }

    /**
     * Превью маршрута для формы создания: какие этапы получатся при выбранной
     * кнопке, и какие маршруты этому виду заявки вообще доступны.
     *
     * Считает бэк тем же резолвером и сборщиком, что и реальная подача, — правило
     * «какой маршрут по умолчанию» живёт в одном месте.
     *
     * Объявлен до /{id}: иначе «route-preview» уйдёт в маршрут карточки.
     */
    #[Route('/route-preview', name: 'spa_api_purchases_route_preview', methods: ['GET'])]
    public function routePreview(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $kind = PurchaseRequestKind::tryFrom((string) $request->query->get('kind', ''))
            ?? PurchaseRequestKind::STANDARD;

        $template = $this->routeDefaults->findByKind($kind)?->getTemplate();
        $usable = $template !== null && $template->isActive() && !$template->isEmpty();

        return $this->json([
            'kind' => $kind->value,
            // Маршрут не настроен — форма обязана сказать это прямо, а не
            // показать пустую цепочку, будто согласований не будет.
            'isConfigured' => $usable,
            'route' => $usable
                ? ['id' => $template->getId(), 'name' => $template->getName()]
                : null,
            'stages' => $usable ? $this->routeBuilder->preview($template) : [],
            'options' => array_map(
                static fn ($t): array => ['id' => $t->getId(), 'name' => $t->getName()],
                $this->templateRepo->findActiveForKind($kind),
            ),
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
        // Какой кнопкой создали — от этого набор полей формы и потолок суммы.
        // Меняться потом не будет: отдел закупок переключает маршрут, но не вид формы.
        $purchase->setCreatedAs(
            PurchaseRequestKind::tryFrom((string) ($payload['createdAs'] ?? '')) ?? PurchaseRequestKind::STANDARD,
        );

        $error = $this->applyPayload($purchase, $payload);
        if ($error !== null) {
            return $error;
        }

        $this->em->persist($purchase);
        $this->workflow->logCreated($purchase, $user);
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

        if (!$this->access->canClassify($purchase, $user)) {
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

        $this->editor->log($purchase, $user, PurchaseHistoryAction::CLASSIFICATION_UPDATED);

        return $this->json($this->presenter->presentDetail($purchase));
    }

    /**
     * Результат ресёрча: поставщик и реальные цены позиций.
     *
     * Правит отдел закупок на своём шаге — до подписи бухгалтерии, юристов,
     * замов и директора, потому что подписывают они именно эти цифры.
     * body: {supplier?: string|null, items?: [{id, estimatedPrice}]}
     */
    #[Route('/{id}/sourcing', name: 'spa_api_purchases_sourcing', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function sourcing(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        // Правит тот, на чьём шаге ресёрча стоит заявка, а не всякий носитель
        // роли: у маршрута может не быть шага закупок вовсе, а у отдела —
        // заявок, до которых ещё не дошла очередь.
        if ($this->access->findMyActiveTask($purchase, $user, PurchaseStagePurpose::SOURCING) === null) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $supplier = array_key_exists('supplier', $payload)
            ? trim((string) ($payload['supplier'] ?? ''))
            : null;

        $prices = [];
        foreach (is_array($payload['items'] ?? null) ? $payload['items'] : [] as $row) {
            if (!is_array($row) || !isset($row['id']) || !isset($row['estimatedPrice'])) {
                continue;
            }
            $prices[(int) $row['id']] = (string) $row['estimatedPrice'];
        }

        try {
            $this->editor->applySourcing($purchase, $user, $supplier, $prices);
        } catch (PurchaseTransitionException $e) {
            return $this->json(['error' => $e->errorCode], Response::HTTP_CONFLICT);
        }

        return $this->json($this->presenter->presentDetail($purchase));
    }

    /**
     * Область видимости списка для пользователя:
     * [createdById|null, visibleStatuses|null].
     *
     * Носитель VIEW_ALL видит весь путь заявки, кроме чужих черновиков;
     * остальные — только свои, а заявки, где они участники маршрута, отдаёт
     * режим as_approver.
     *
     * Раньше здесь было два набора статусов — директору и отделу закупок, — и
     * различались они одним REJECTED. Разделять из-за него право надвое незачем:
     * возврат автору для закупок не секрет, а полномочие стало одно.
     *
     * @return array{0: int|null, 1: list<PurchaseStatus>|null}
     */
    private function resolveScope(User $user): array
    {
        if ($this->access->can($user, PurchaseCapability::VIEW_ALL)) {
            return [null, PurchaseStatus::getNonDraft()];
        }

        // Любой авторизованный пользователь видит свои заявки.
        return [(int) $user->getId(), null];
    }

    /** Автор заявки — роль не требуется: создавать может любой пользователь. */
    private function isManagerOwner(PurchaseRequest $purchase, User $user): bool
    {
        return $this->access->isOwner($purchase, $user);
    }

    /** Видимость заявки — общая на весь модуль, см. PurchaseAccess. */
    private function canView(PurchaseRequest $purchase, User $user): bool
    {
        return $this->access->canView($purchase, $user);
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


        $technicalSpec = $payload['technicalSpec'] ?? null;
        $purchase->setTechnicalSpec(is_string($technicalSpec) && trim($technicalSpec) !== '' ? trim($technicalSpec) : null);

        $error = $this->applyCategory($purchase, $payload);
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
            $description = trim((string) ($itemPayload['description'] ?? ''));
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
            $item->setDescription($description !== '' ? $description : null);
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
     * Классификация целиком — только для PATCH /classification.
     *
     * Закон и способ закупки автор не заполняет: это работа отдела закупок при
     * рассмотрении. В форме заявки их нет, и принимать их оттуда незачем —
     * иначе поле, которого нет в интерфейсе, всё равно можно проставить запросом.
     */
    private function applyClassification(PurchaseRequest $purchase, array $payload): ?JsonResponse
    {
        $error = $this->applyCategory($purchase, $payload);
        if ($error !== null) {
            return $error;
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

    /** Категорию выбирает автор в форме, поэтому она отдельно от закона и способа. */
    private function applyCategory(PurchaseRequest $purchase, array $payload): ?JsonResponse
    {
        if (!array_key_exists('categoryId', $payload)) {
            return null;
        }

        $categoryId = $payload['categoryId'];
        if ($categoryId === null || $categoryId === '') {
            $purchase->setCategory(null);

            return null;
        }

        $category = $this->categoryRepo->find((int) $categoryId);
        if ($category === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_CATEGORY_NOT_FOUND], Response::HTTP_BAD_REQUEST);
        }
        $purchase->setCategory($category);

        return null;
    }
}
