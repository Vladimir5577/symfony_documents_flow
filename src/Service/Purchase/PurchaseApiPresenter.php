<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Entity\Purchase\PurchaseApprovalStage;
use App\Entity\Purchase\PurchaseApprovalTask;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestComment;
use App\Entity\Purchase\PurchaseRequestFile;
use App\Entity\Purchase\PurchaseRequestHistory;
use App\Entity\Purchase\PurchaseRequestItem;
use App\Entity\Purchase\PurchaseRouteTemplate;
use App\Entity\Purchase\PurchaseRouteTemplateStage;
use App\Entity\Purchase\PurchaseRouteTemplateTask;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseCapability;
use App\Enum\Purchase\PurchaseStagePurpose;
use App\Enum\Purchase\PurchaseStatus;
use App\Service\SpaApi\Documents\DocumentApiPresenter;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Форматирование заявок закупок для SpaApi.
 *
 * Маршрут отдаётся деревом: этапы, внутри — задачи. Плоский список шагов, где
 * параллельность выражалась совпадением позиций, фронт вынужден был группировать
 * сам — то есть повторять правило, живущее на сервере.
 */
final class PurchaseApiPresenter
{
    public function __construct(
        private readonly Security $security,
        private readonly PurchaseAccess $access,
        private readonly ApprovalRouteResolver $resolver,
        private readonly DocumentApiPresenter $documentPresenter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function presentListItem(PurchaseRequest $request): array
    {
        $status = $request->getStatus();
        $priority = $request->getPriority();
        $law = $request->getLaw();
        $method = $request->getMethod();

        return [
            'id' => $request->getId(),
            'title' => $request->getTitle(),
            'status' => ['value' => $status->value, 'label' => $status->getLabel()],
            'priority' => ['value' => $priority->value, 'label' => $priority->getLabel()],
            'organization' => [
                'id' => $request->getOrganization()?->getId(),
                'name' => $request->getOrganization()?->getName(),
                // Полный путь «Организация / Филиал / Департамент» — как во входящих документах
                'path' => $request->getOrganization() !== null
                    ? $this->documentPresenter->buildOrganizationPath($request->getOrganization())
                    : null,
            ],
            'category' => $request->getCategory() !== null
                ? ['id' => $request->getCategory()->getId(), 'name' => $request->getCategory()->getName()]
                : null,
            'law' => $law !== null ? ['value' => $law->value, 'label' => $law->getLabel()] : null,
            'method' => $method !== null ? ['value' => $method->value, 'label' => $method->getLabel()] : null,
            // Описание нужно в списке: директор читает его в ховере по строке и
            // в разборе новых заявок, а второй раз за карточкой не пойдёт.
            'description' => $request->getDescription(),
            'createdBy' => $this->presentUser($request->getCreatedBy()),
            'executor' => $this->presentUser($request->getExecutor()),
            'totalAmount' => $request->getTotalAmount(),
            'itemsCount' => $request->getItems()->count(),
            // Кнопка создания — от неё форма редактирования и потолок быстрой заявки
            'createdAs' => [
                'value' => $request->getCreatedAs()->value,
                'label' => $request->getCreatedAs()->getLabel(),
            ],
            // По какому регламенту заявка идёт — снимок названия на момент подачи
            'route' => [
                'templateId' => $request->getAppliedRouteTemplate()?->getId(),
                'name' => $request->getAppliedRouteTemplateName(),
            ],
            // «У кого заявка» — данные этапа, а не статус
            'currentStage' => $this->presentCurrentStageSummary($request),
            // Моя подпись на этой заявке, если её ещё можно снять. Нужна строке
            // списка: без неё тоггл в таблице не знает, что откатывать.
            // Маршрут здесь и так уже загружен ради currentStage.
            'myApprovedTaskId' => $this->findRevokableTask($request)?->getId(),
            'dueDate' => $request->getDueDate()?->format('Y-m-d'),
            'createdAt' => $request->getCreatedAt()?->format('c'),
            'updatedAt' => $request->getUpdatedAt()?->format('c'),
        ];
    }

    /**
     * Карточка: список + позиции, комментарии, история, файлы и доступные действия.
     *
     * @return array<string, mixed>
     */
    public function presentDetail(PurchaseRequest $request): array
    {
        $data = $this->presentListItem($request);

        $data['technicalSpec'] = $request->getTechnicalSpec();
        $data['supplier'] = $request->getSupplier();
        // array_values: после removeElement ключи коллекции дырявые → JSON-объект, не массив.
        $data['stages'] = array_values(array_map(
            fn (PurchaseApprovalStage $stage): array => $this->presentStage($stage),
            $request->getStages()->toArray(),
        ));
        $data['items'] = array_values(array_map(
            fn (PurchaseRequestItem $item): array => [
                'id' => $item->getId(),
                'name' => $item->getName(),
                'description' => $item->getDescription(),
                'quantity' => $item->getQuantity(),
                'unit' => $item->getUnit(),
                'estimatedPrice' => $item->getEstimatedPrice(),
                'position' => $item->getPosition(),
                // Решение разбирающего по позиции: снял галочку и/или урезал количество.
                // Заявленное автором остаётся в quantity — модалка показывает обе цифры.
                'excluded' => $item->isExcluded(),
                'approvedQuantity' => $item->getApprovedQuantity(),
                'categoryItemId' => $item->getCategoryItem()?->getId(),
            ],
            $request->getItems()->toArray(),
        ));
        $data['comments'] = array_values(array_map(
            fn (PurchaseRequestComment $comment): array => [
                'id' => $comment->getId(),
                'author' => $this->presentUser($comment->getAuthor()),
                'text' => $comment->getText(),
                'createdAt' => $comment->getCreatedAt()?->format('c'),
            ],
            $request->getComments()->toArray(),
        ));
        $data['history'] = array_values(array_map(
            fn (PurchaseRequestHistory $entry): array => [
                'id' => $entry->getId(),
                'user' => $this->presentUser($entry->getUser()),
                // Код события: лента строится по нему, а не разбором комментария
                'action' => $entry->getAction() !== null
                    ? ['value' => $entry->getAction()->value, 'label' => $entry->getAction()->getLabel()]
                    : null,
                'fromStatus' => $entry->getFromStatus() !== null
                    ? ['value' => $entry->getFromStatus()->value, 'label' => $entry->getFromStatus()->getLabel()]
                    : null,
                'toStatus' => [
                    'value' => $entry->getToStatus()->value,
                    'label' => $entry->getToStatus()->getLabel(),
                ],
                'comment' => $entry->getComment(),
                'createdAt' => $entry->getCreatedAt()?->format('c'),
            ],
            $request->getHistory()->toArray(),
        ));
        $data['files'] = array_values(array_map(
            fn (PurchaseRequestFile $file): array => $this->presentFile($file),
            $request->getFiles()->toArray(),
        ));
        $data['actions'] = $this->presentActions($request);

        return $data;
    }

    /**
     * Этап маршрута для степпера: что за этап, кого ждут и в каком он состоянии.
     *
     * @return array<string, mixed>
     */
    private function presentStage(PurchaseApprovalStage $stage): array
    {
        $status = $stage->getStatus();
        $purpose = $stage->getPurpose();

        return [
            'id' => $stage->getId(),
            'position' => $stage->getPosition(),
            'title' => $stage->resolveTitle(),
            // Что на этапе делают. Фронту нужно отличать разбор и ресёрч от
            // обычной подписи, и выводить это из роли он больше не должен.
            'purpose' => ['value' => $purpose->value, 'label' => $purpose->getLabel()],
            'isExecution' => $purpose->isExecution(),
            'status' => ['value' => $status->value, 'label' => $status->getLabel()],
            'isActive' => $stage->isActive(),
            // Этап, куда разбирающий ещё не выбрал людей: степпер показывает его
            // как «ожидает назначения», а не как пустой провал в маршруте.
            'awaitingAssignment' => $stage->isAwaitingAssignment(),
            'candidateRole' => $stage->getCandidateRoleCode() !== null
                ? [
                    'value' => $stage->getCandidateRoleCode()->value,
                    'label' => $stage->getCandidateRoleCode()->getLabel(),
                ]
                : null,
            'startedAt' => $stage->getStartedAt()?->format('c'),
            'completedAt' => $stage->getCompletedAt()?->format('c'),
            'tasks' => array_values(array_map(
                fn (PurchaseApprovalTask $task): array => $this->presentTask($task, $stage),
                $stage->getTasks()->toArray(),
            )),
        ];
    }

    /**
     * Задача этапа: кого ждали и кто фактически решил.
     *
     * @return array<string, mixed>
     */
    private function presentTask(PurchaseApprovalTask $task, PurchaseApprovalStage $stage): array
    {
        // Название роли берём из снимка: её могли переименовать или убрать из
        // enum, а задача должна читаться так, как читалась в день подписи.
        $roleLabel = $task->getRoleName();

        return [
            'id' => $task->getId(),
            'title' => $task->resolveTitle(),
            'assignmentType' => $task->getAssignmentType()->value,
            // Кого ждали: роль ИЛИ человек. У ролевой задачи assigneeUser пуст
            // намеренно — ждали любого носителя, подписант лежит в decidedBy.
            'approverRole' => $roleLabel !== null
                ? ['value' => $task->getRoleCode()?->value, 'label' => $roleLabel]
                : null,
            'approverUser' => $this->presentUser($task->getAssigneeUser()),
            'requiresFileType' => $task->getRequiresFileType()?->value,
            'decision' => [
                'value' => $task->getDecision()->value,
                'label' => $task->getDecision()->getLabel(),
            ],
            'decidedBy' => $this->presentUser($task->getDecidedBy()),
            'decidedAt' => $task->getDecidedAt()?->format('c'),
            'comment' => $task->getComment(),
            'isActive' => $task->isPending() && $stage->isActive(),
            'isMine' => $this->canActOn($task),
        ];
    }

    /** Короткая сводка «у кого сейчас» для строки списка. */
    private function presentCurrentStageSummary(PurchaseRequest $request): ?array
    {
        $stage = $request->getCurrentStage();
        if ($stage === null) {
            return null;
        }

        $labels = [];
        foreach ($stage->getPendingTasks() as $task) {
            $labels[] = $task->resolveTitle();
        }

        return [
            'id' => $stage->getId(),
            'position' => $stage->getPosition(),
            'title' => $stage->resolveTitle(),
            'purpose' => $stage->getPurpose()->value,
            'labels' => array_values(array_unique($labels)),
            'isMine' => $this->findMyActiveTask($request) !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentFile(PurchaseRequestFile $file): array
    {
        return [
            'id' => $file->getId(),
            'originalName' => $file->getOriginalName(),
            'type' => ['value' => $file->getType()->value, 'label' => $file->getType()->getLabel()],
            'uploadedBy' => $this->presentUser($file->getUploadedBy()),
            'createdAt' => $file->getCreatedAt()?->format('c'),
            'canDelete' => $this->canDeleteFile($file),
            'downloadUrl' => sprintf(
                '/spa/api/purchases/%d/files/%d/download',
                $file->getPurchaseRequest()?->getId(),
                $file->getId(),
            ),
        ];
    }

    /** Заготовка маршрута для админки и для выбора при разборе. */
    public function presentRouteTemplate(PurchaseRouteTemplate $template): array
    {
        return [
            'id' => $template->getId(),
            'code' => $template->getCode(),
            'name' => $template->getName(),
            'description' => $template->getDescription(),
            'isActive' => $template->isActive(),
            'sortOrder' => $template->getSortOrder(),
            // Форма правки обязана вернуть эту версию обратно: по ней видно, что
            // админ правил тот маршрут, который открывал.
            'version' => $template->getVersion(),
            'allowedKinds' => array_map(
                static fn ($kind): string => $kind->value,
                $template->getAllowedKinds(),
            ),
            'updatedBy' => $this->presentUser($template->getUpdatedBy()),
            'updatedAt' => $template->getUpdatedAt()?->format('c'),
            'stages' => array_values(array_map(
                static fn (PurchaseRouteTemplateStage $stage): array => [
                    'position' => $stage->getPosition(),
                    'title' => $stage->getTitle(),
                    'purpose' => $stage->getPurpose()->value,
                    'allowsReject' => $stage->allowsReject(),
                    'tasks' => array_values(array_map(
                        static fn (PurchaseRouteTemplateTask $task): array => [
                            'position' => $task->getPosition(),
                            'assignmentType' => $task->getAssignmentType()->value,
                            'roleCode' => $task->getRoleCode()?->value,
                            'candidateRoleCode' => $task->getCandidateRoleCode()?->value,
                            'title' => $task->getTitle(),
                            'requiresFileType' => $task->getRequiresFileType()?->value,
                        ],
                        $stage->getTasks()->toArray(),
                    )),
                ],
                $template->getStages()->toArray(),
            )),
        ];
    }

    /** Зеркалит гейт PurchaseFileController::delete() — фронт по нему прячет корзину. */
    private function canDeleteFile(PurchaseRequestFile $file): bool
    {
        $request = $file->getPurchaseRequest();
        $user = $this->security->getUser();
        if ($request === null || !$user instanceof User) {
            return false;
        }

        $status = $request->getStatus();
        if ($file->getType()->isLockedAt($status)) {
            return false;
        }

        return $file->getUploadedBy()?->getId() === $user->getId()
            || ($request->getCreatedBy()?->getId() === $user->getId() && $status->isEditable());
    }

    /**
     * Доступные текущему пользователю действия — фронт рисует кнопки по ним.
     *
     * Ни одна кнопка согласования не спрашивает «какой статус»: единственный
     * вопрос — стоит ли указатель на моей задаче. Это касается и оплаты с
     * поставкой: они стали задачами маршрута, и зашитого списка «кто и когда имеет
     * право закрыть заявку» здесь больше нет.
     *
     * @return array<string, mixed>
     */
    private function presentActions(PurchaseRequest $request): array
    {
        $user = $this->security->getUser();
        $status = $request->getStatus();

        // За JWT-файрволом недостижимо, но presenter обязан быть безопасным сам:
        // без пользователя ни одна кнопка не рисуется.
        if (!$user instanceof User) {
            return [];
        }

        $isOwner = $this->access->isOwner($request, $user);
        $myTask = $this->access->findMyActiveTask($request, $user);
        $revokableTask = $this->access->findMyRevokableTask($request, $user);
        $stage = $myTask?->getStage();

        $assignableStages = $this->access->findAssignableStages($request, $user);

        return [
            'canEdit' => $isOwner && $status->isEditable(),
            'canDelete' => $isOwner && $status === PurchaseStatus::DRAFT,
            'canSubmit' => $isOwner && $status->isEditable(),
            // Согласовать/вернуть — одна пара кнопок на всех, включается задачей
            'canApproveTask' => $myTask !== null,
            // Возврат автору разрешает этап: на исполнении его нет — деньги ушли
            'canRejectTask' => $myTask !== null && $stage?->allowsReject() === true,
            'canReturnToSourcing' => $this->access->canReturnToSourcing($request, $user),
            'activeTaskId' => $myTask?->getId(),
            // Снять можно только свою подпись — «кто именно» решает сама задача.
            'canRevokeTask' => $revokableTask !== null,
            'revokableTaskId' => $revokableTask?->getId(),
            'canClassify' => $this->access->canClassify($request, $user),
            // Куда назначать согласантов: динамические этапы задаются в маршруте,
            // и без этого модалка разбора предлагала бы отметить людей, которых
            // сервер не примет.
            'assignableStages' => array_map(
                static fn (PurchaseApprovalStage $s): array => [
                    'id' => $s->getId(),
                    'title' => $s->resolveTitle(),
                    'candidateRoleCode' => $s->getCandidateRoleCode()?->value,
                ],
                $assignableStages,
            ),
            'canAssignApprovers' => $assignableStages !== [],
            // Сменить маршрут можно только на разборе: дальше в маршруте уже
            // лежат чужие решения, и пересборка сожгла бы их.
            'canChangeRoute' => $this->access->canChangeRoute($request, $user),
            'routeOptions' => $this->access->canChangeRoute($request, $user)
                ? array_map(
                    static fn (PurchaseRouteTemplate $t): array => [
                        'id' => $t->getId(),
                        'code' => $t->getCode(),
                        'name' => $t->getName(),
                    ],
                    $this->resolver->options($request),
                )
                : [],
            // Поставщик и цены — работа этапа ресёрча, и только пока он активен.
            // Роль здесь не спрашиваем: задача моя — значит она мне и адресована.
            'canEditSourcing' => $stage?->getPurpose() === PurchaseStagePurpose::SOURCING,
            'canCancel' => $this->access->canCancel($request, $user),
            'canSetPriority' => !$status->isFinal()
                && $this->access->can($user, PurchaseCapability::SUPERVISE),
            'canComment' => $this->access->canView($request, $user),
        ];
    }

    /** Активная задача, по которой текущий пользователь вправе принять решение. */
    private function findMyActiveTask(PurchaseRequest $request): ?PurchaseApprovalTask
    {
        $user = $this->security->getUser();

        return $user instanceof User
            ? $this->access->findMyActiveTask($request, $user)
            : null;
    }

    /** Задача, подписанная лично мной, которую ещё можно откатить. */
    private function findRevokableTask(PurchaseRequest $request): ?PurchaseApprovalTask
    {
        $user = $this->security->getUser();

        return $user instanceof User
            ? $this->access->findMyRevokableTask($request, $user)
            : null;
    }

    /**
     * Задача адресована мне. Считает PurchaseAccess — тот же вызов, что и в гейте
     * контроллера: иначе кнопка и право разъезжаются.
     */
    private function canActOn(PurchaseApprovalTask $task): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && $this->access->canActOn($task, $user);
    }

    /**
     * @return array{id: int|null, name: string, position: string|null}|null
     */
    private function presentUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $name = trim(($user->getLastname() ?? '') . ' ' . ($user->getFirstname() ?? ''));

        return [
            'id' => $user->getId(),
            'name' => $name !== '' ? $name : (string) $user->getLogin(),
            'position' => $user->getWorker()?->getProfession(),
        ];
    }
}
