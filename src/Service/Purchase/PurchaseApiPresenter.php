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
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\Purchase\PurchaseStepDecision;
use App\Enum\User\UserRole;
use App\Service\SpaApi\Documents\DocumentApiPresenter;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Форматирование заявок закупок для SpaApi.
 */
final class PurchaseApiPresenter
{
    public function __construct(
        private readonly Security $security,
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
            // Обоснование нужно в списке: директор видит его в ховере по строке
            'justification' => $request->getJustification(),
            'createdBy' => $this->presentUser($request->getCreatedBy()),
            'executor' => $this->presentUser($request->getExecutor()),
            'totalAmount' => $request->getTotalAmount(),
            'itemsCount' => $request->getItems()->count(),
            // Кнопка создания — от неё форма редактирования и потолок; шаблон меняется
            'createdAs' => [
                'value' => $request->getCreatedAs()->value,
                'label' => $request->getCreatedAs()->getLabel(),
            ],
            'routeTemplate' => $request->getRouteTemplate() !== null
                ? ['id' => $request->getRouteTemplate()->getId(), 'name' => $request->getRouteTemplate()->getName()]
                : null,
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

        $data['description'] = $request->getDescription();
        $data['technicalSpec'] = $request->getTechnicalSpec();
        // array_values: после removeElement ключи коллекции дырявые → JSON-объект, не массив.
        $data['steps'] = array_values(array_map(
            fn (PurchaseApprovalStep $step): array => $this->presentStep($step, $request),
            $request->getSteps()->toArray(),
        ));
        $data['items'] = array_values(array_map(
            fn (PurchaseRequestItem $item): array => [
                'id' => $item->getId(),
                'name' => $item->getName(),
                'quantity' => $item->getQuantity(),
                'unit' => $item->getUnit(),
                'estimatedPrice' => $item->getEstimatedPrice(),
                'position' => $item->getPosition(),
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
        $role = $step->getApproverRole() !== null
            ? UserRole::tryFrom($step->getApproverRole())
            : null;

        return [
            'id' => $step->getId(),
            'position' => $step->getPosition(),
            'title' => $step->getTitle() ?? $role?->getLabel(),
            // Кого ждали: роль ИЛИ человек. У ролевого шага approverUser пуст
            // намеренно — ждали любого носителя, подписант лежит в decidedBy.
            'approverRole' => $role !== null
                ? ['value' => $role->value, 'label' => $role->getLabel()]
                : null,
            'approverUser' => $this->presentUser($step->getApproverUser()),
            'requiresFileType' => $step->getRequiresFileType()?->value,
            'mandatory' => $step->isMandatory(),
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
            $role = $step->getApproverRole() !== null ? UserRole::tryFrom($step->getApproverRole()) : null;
            $labels[] = $step->getTitle()
                ?? $role?->getLabel()
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

        $isDirector = $this->security->isGranted(UserRole::ROLE_PURCHASE_DIRECTOR->value);
        $isFinance = $this->security->isGranted(UserRole::ROLE_PURCHASE_FINANCE_DIRECTOR->value);
        $isPurchase = $this->security->isGranted(UserRole::ROLE_PURCHASE_DEPARTMENT->value);
        $isPayer = $this->security->isGranted(UserRole::ROLE_PURCHASE_INVOICE->value);
        // Автор заявки — роль не требуется: создавать может любой пользователь.
        $isOwner = $user instanceof User
            && $request->getCreatedBy()?->getId() === $user->getId();
        $isParticipant = $user instanceof User && $this->isRouteParticipant($request, $user);

        $myStep = $this->findMyActiveStep($request);
        $revokableStep = $this->findRevokableStep($request);
        $onApproval = $status === PurchaseStatus::ON_APPROVAL;
        // Маршрут правится, только пока указатель на первом шаге отдела закупок
        $routeEditable = $onApproval
            && $myStep !== null
            && $isPurchase
            && $myStep->getApproverRole() === UserRole::ROLE_PURCHASE_DEPARTMENT->value
            && $request->getCurrentPosition() === $this->firstDepartmentPosition($request);

        $nextStatus = $status->nextExecutionStatus();
        // Конвейер ведёт отдел закупок; плательщик — только отметку «Оплачено».
        $canAdvance = $nextStatus !== null
            && ($isPurchase || ($isPayer && $status === PurchaseStatus::INVOICE_SENT));

        $seesEverything = ($isDirector || $isFinance)
            && in_array($status, PurchaseStatus::getDirectorVisible(), true);
        $canView = $seesEverything
            || ($isPurchase && in_array($status, PurchaseStatus::getPurchaseDepartmentVisible(), true))
            || ($isPayer && in_array($status, PurchaseStatus::getPayerVisible(), true))
            || $isOwner
            || $isParticipant;

        return [
            'canEdit' => $isOwner && $status->isEditable(),
            'canDelete' => $isOwner && $status === PurchaseStatus::DRAFT,
            'canSubmit' => $isOwner && $status->isEditable(),
            // Согласовать/вернуть — одна пара кнопок на всех, включается шагом
            'canApproveStep' => $myStep !== null,
            'canRejectStep' => $myStep !== null,
            'activeStepId' => $myStep?->getId(),
            // Снять свою подпись может только директор и только своё решение
            'canRevokeStep' => $isDirector && $revokableStep !== null,
            'revokableStepId' => $isDirector ? $revokableStep?->getId() : null,
            'canEditRoute' => $routeEditable,
            'canRecall' => $isPurchase && $onApproval && !$routeEditable,
            'canClassify' => $isPurchase && $onApproval,
            'canAdvance' => $canAdvance,
            'nextStatus' => $canAdvance
                ? ['value' => $nextStatus->value, 'label' => $nextStatus->getLabel()]
                : null,
            'canConfirm' => ($isOwner || $isPurchase) && $status === PurchaseStatus::DELIVERED,
            'canCancel' => $this->canCancel($status, $isDirector, $isOwner, $isPurchase),
            'canSetPriority' => $isDirector && !$status->isFinal(),
            'canComment' => $canView,
        ];
    }

    /** Активный шаг, по которому текущий пользователь вправе принять решение. */
    private function findMyActiveStep(PurchaseRequest $request): ?PurchaseApprovalStep
    {
        if ($request->getStatus() !== PurchaseStatus::ON_APPROVAL) {
            return null;
        }

        foreach ($request->getActiveSteps() as $step) {
            if ($this->canActOn($step)) {
                return $step;
            }
        }

        return null;
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

    /** Шаг адресован мне: лично или через роль, которая на нём записана. */
    private function canActOn(PurchaseApprovalStep $step): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return false;
        }
        if ($step->isAddressedTo($user)) {
            return true;
        }

        $role = $step->getApproverRole();

        return $role !== null && $this->security->isGranted($role);
    }

    /** Человек есть в маршруте на любом шаге — этого достаточно, чтобы читать заявку. */
    private function isRouteParticipant(PurchaseRequest $request, User $user): bool
    {
        foreach ($request->getSteps() as $step) {
            if ($step->isAddressedTo($user)) {
                return true;
            }
            $role = $step->getApproverRole();
            if ($role !== null && $this->security->isGranted($role)) {
                return true;
            }
        }

        return false;
    }

    private function firstDepartmentPosition(PurchaseRequest $request): int
    {
        $min = null;
        foreach ($request->getSteps() as $step) {
            if ($step->getApproverRole() === UserRole::ROLE_PURCHASE_DEPARTMENT->value
                && ($min === null || $step->getPosition() < $min)
            ) {
                $min = $step->getPosition();
            }
        }

        return $min ?? 1;
    }

    /** Отмена: автор — пока не начали исполнять; отдел закупок — на исполнении; директор — всегда (до финала). */
    private function canCancel(PurchaseStatus $status, bool $isDirector, bool $isOwner, bool $isPurchase): bool
    {
        if ($status->isFinal()) {
            return false;
        }
        if ($isDirector) {
            return true;
        }
        if ($isOwner) {
            return in_array($status, [
                PurchaseStatus::DRAFT,
                PurchaseStatus::ON_APPROVAL,
                PurchaseStatus::APPROVED,
                PurchaseStatus::REJECTED,
            ], true);
        }
        if ($isPurchase) {
            return in_array($status, [
                PurchaseStatus::INVOICE_SENT,
                PurchaseStatus::INVOICE_PAID,
                PurchaseStatus::DELIVERED,
            ], true);
        }

        return false;
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
