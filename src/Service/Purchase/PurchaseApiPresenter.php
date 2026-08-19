<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Entity\Purchase\PurchaseApprovalStep;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestComment;
use App\Entity\Purchase\PurchaseRequestFile;
use App\Entity\Purchase\PurchaseRequestHistory;
use App\Entity\Purchase\PurchaseRequestItem;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseCapability;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\Purchase\PurchaseStepDecision;
use App\Enum\Purchase\PurchaseStepPurpose;
use App\Service\SpaApi\Documents\DocumentApiPresenter;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Форматирование заявок закупок для SpaApi.
 */
final class PurchaseApiPresenter
{
    public function __construct(
        private readonly Security $security,
        private readonly PurchaseAccess $access,
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
            // «У кого заявка» — данные шага, а не статус
            'currentStep' => $this->presentCurrentStepSummary($request),
            // Моя подпись на этой заявке, если её ещё можно снять. Нужна строке
            // списка: без неё тоггл в таблице не знает, что откатывать.
            // Коллекция шагов здесь и так уже загружена ради currentStep.
            'myApprovedStepId' => $this->findRevokableStep($request)?->getId(),
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
        $data['steps'] = array_values(array_map(
            fn (PurchaseApprovalStep $step): array => $this->presentStep($step, $request),
            $request->getSteps()->toArray(),
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
                // Решение директора по позиции: снял галочку и/или урезал количество.
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
     * Шаг маршрута для степпера: что за шаг, кого ждали и кто фактически решил.
     *
     * @return array<string, mixed>
     */
    private function presentStep(PurchaseApprovalStep $step, PurchaseRequest $request): array
    {
        // Название берём из снимка: роль могли переименовать или убрать из enum,
        // а шаг должен читаться так, как читался в день подписи.
        $roleLabel = $step->getRoleName();

        return [
            'id' => $step->getId(),
            'position' => $step->getPosition(),
            'title' => $step->getTitle() ?? $roleLabel,
            // Кого ждали: роль ИЛИ человек. У ролевого шага approverUser пуст
            // намеренно — ждали любого носителя, подписант лежит в decidedBy.
            'approverRole' => $roleLabel !== null
                ? [
                    'value' => $step->getRoleCode()?->value,
                    'label' => $roleLabel,
                ]
                : null,
            'approverUser' => $this->presentUser($step->getApproverUser()),
            // Что на шаге делают. Фронту нужно отличать разбор и ресёрч от
            // обычной подписи, и выводить это из роли он больше не должен.
            'purpose' => [
                'value' => $step->getPurpose()->value,
                'label' => $step->getPurpose()->getLabel(),
            ],
            'requiresFileType' => $step->getRequiresFileType()?->value,
            'decision' => [
                'value' => $step->getDecision()->value,
                'label' => $step->getDecision()->getLabel(),
            ],
            'decidedBy' => $this->presentUser($step->getDecidedBy()),
            'decidedAt' => $step->getDecidedAt()?->format('c'),
            'comment' => $step->getComment(),
            'isActive' => $step->isPending() && $step->getPosition() === $request->getCurrentPosition(),
            'isMine' => $this->canActOn($step),
        ];
    }

    /** Короткая сводка «у кого сейчас» для строки списка. */
    private function presentCurrentStepSummary(PurchaseRequest $request): ?array
    {
        $active = $request->getActiveSteps();
        if ($active === []) {
            return null;
        }

        $labels = [];
        foreach ($active as $step) {
            $labels[] = $step->getTitle()
                ?? $step->getRoleName()
                ?? $this->presentUser($step->getApproverUser())['name']
                ?? 'Согласование';
        }

        return [
            'position' => $active[0]->getPosition(),
            'labels' => array_values(array_unique($labels)),
            'isMine' => $this->findMyActiveStep($request) !== null,
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
     * Согласование больше не спрашивает «какой статус»: единственный вопрос —
     * стоит ли указатель на моём шаге. Роль читается из самого шага, поэтому
     * зашитого списка «кто и когда имеет право подписать» здесь нет.
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
        $canRunExecution = $this->access->can($user, PurchaseCapability::RUN_EXECUTION);

        $myStep = $this->findMyActiveStep($request);
        $revokableStep = $this->findRevokableStep($request);

        $nextStatus = $status->nextExecutionStatus();
        $canAdvance = $this->access->canAdvanceTo($request, $user, $nextStatus);

        // Вернуть в закупки может тот, кто идёт после них: он бракует документы,
        // а не саму заявку. У быстрой заявки закупки первые — возвращать некуда.
        $sourcingPosition = $this->findSourcingPosition($request);
        $canReturnToDepartment = $myStep !== null
            && $sourcingPosition !== null
            && $myStep->getPosition() > $sourcingPosition;

        return [
            'canEdit' => $isOwner && $status->isEditable(),
            'canDelete' => $isOwner && $status === PurchaseStatus::DRAFT,
            'canSubmit' => $isOwner && $status->isEditable(),
            // Согласовать/вернуть — одна пара кнопок на всех, включается шагом
            'canApproveStep' => $myStep !== null,
            'canRejectStep' => $myStep !== null,
            'canReturnToDepartment' => $canReturnToDepartment,
            'activeStepId' => $myStep?->getId(),
            // Снять можно только свою подпись — «кто именно» решает сам шаг.
            // Отдельного гейта «только директор» здесь нет: он расходился со
            // строкой списка, где кнопка показывалась любому подписавшему.
            'canRevokeStep' => $revokableStep !== null,
            'revokableStepId' => $revokableStep?->getId(),
            'canClassify' => $this->access->canClassify($request, $user),
            // Есть ли куда назначать замов: слот под них задаётся в маршруте, и
            // модалка разбора без этого флага предлагала бы отметить людей,
            // которых сервер не примет.
            'canAssignApprovers' => $this->access->canAssignApprovers($request, $user),
            // Поставщик и цены — работа шага ресёрча, и только пока он активен.
            // Роль здесь не спрашиваем: шаг мой — значит он мне и адресован.
            'canEditSourcing' => $myStep?->getPurpose() === PurchaseStepPurpose::SOURCING,
            'canAdvance' => $canAdvance,
            'nextStatus' => $canAdvance && $nextStatus !== null
                ? ['value' => $nextStatus->value, 'label' => $nextStatus->getLabel()]
                : null,
            // Закрытие в архив: конвейер исполнения, и только когда УПД приложен
            'canConfirm' => $canRunExecution && $status === PurchaseStatus::DELIVERED
                && $request->hasFileOfType(PurchaseFileType::UPD),
            'canCancel' => $this->access->canCancel($request, $user),
            'canSetPriority' => !$status->isFinal()
                && $this->access->can($user, PurchaseCapability::SUPERVISE),
            'canComment' => $this->access->canView($request, $user),
        ];
    }

    /** Позиция шага ресёрча — граница, до которой можно откатить заявку. */
    private function findSourcingPosition(PurchaseRequest $request): ?int
    {
        $position = null;
        foreach ($request->getSteps() as $step) {
            if ($step->getPurpose() !== PurchaseStepPurpose::SOURCING) {
                continue;
            }
            if ($position === null || $step->getPosition() < $position) {
                $position = $step->getPosition();
            }
        }

        return $position;
    }

    /** Активный шаг, по которому текущий пользователь вправе принять решение. */
    private function findMyActiveStep(PurchaseRequest $request): ?PurchaseApprovalStep
    {
        $user = $this->security->getUser();

        return $user instanceof User
            ? $this->access->findMyActiveStep($request, $user)
            : null;
    }

    /**
     * Шаг, подписанный лично мной, который ещё можно откатить.
     *
     * Пока заявка не ушла в исполнение: ON_APPROVAL или APPROVED. После счёта
     * откатывать нечего — деньги уже пошли, там только отмена заявки.
     */
    private function findRevokableStep(PurchaseRequest $request): ?PurchaseApprovalStep
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }
        if (!in_array($request->getStatus(), [PurchaseStatus::ON_APPROVAL, PurchaseStatus::APPROVED], true)) {
            return null;
        }

        foreach ($request->getSteps() as $step) {
            if ($step->getDecision() === PurchaseStepDecision::APPROVED
                && $step->getDecidedBy()?->getId() === $user->getId()
            ) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Шаг адресован мне. Считает PurchaseAccess — тот же вызов, что и в гейте
     * контроллера: иначе кнопка и право разъезжаются.
     */
    private function canActOn(PurchaseApprovalStep $step): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && $this->access->canActOn($step, $user);
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
