<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestApprover;
use App\Entity\Purchase\PurchaseRequestComment;
use App\Entity\Purchase\PurchaseRequestFile;
use App\Entity\Purchase\PurchaseRequestHistory;
use App\Entity\Purchase\PurchaseRequestItem;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseStatus;
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
        $data['approvers'] = array_map(
            fn (PurchaseRequestApprover $approver): array => [
                'id' => $approver->getId(),
                'user' => $this->presentUser($approver->getUser()),
                'invitedBy' => $this->presentUser($approver->getInvitedBy()),
                'confirmedAt' => $approver->getConfirmedAt()?->format('c'),
                'createdAt' => $approver->getCreatedAt()?->format('c'),
            ],
            $request->getApprovers()->toArray(),
        );
        $data['items'] = array_map(
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
        );
        $data['comments'] = array_map(
            fn (PurchaseRequestComment $comment): array => [
                'id' => $comment->getId(),
                'author' => $this->presentUser($comment->getAuthor()),
                'text' => $comment->getText(),
                'createdAt' => $comment->getCreatedAt()?->format('c'),
            ],
            $request->getComments()->toArray(),
        );
        $data['history'] = array_map(
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
        );
        $data['files'] = array_map(
            fn (PurchaseRequestFile $file): array => $this->presentFile($file),
            $request->getFiles()->toArray(),
        );
        $data['actions'] = $this->presentActions($request);

        return $data;
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
     * Логика зеркалит гейты контроллеров: роль + статус + владение/согласант.
     *
     * @return array<string, mixed>
     */
    private function presentActions(PurchaseRequest $request): array
    {
        $user = $this->security->getUser();
        $status = $request->getStatus();

        $isDirector = $this->security->isGranted(UserRole::ROLE_PURCHASE_DIRECTOR->value);
        $isPurchase = $this->security->isGranted(UserRole::ROLE_PURCHASE_DEPARTMENT->value);
        $isPayer = $this->security->isGranted(UserRole::ROLE_PURCHASE_INVOICE->value);
        // Автор заявки — роль не требуется: создавать может любой пользователь.
        $isOwner = $user instanceof User
            && $request->getCreatedBy()?->getId() === $user->getId();
        $isApprover = $user instanceof User && $request->findApproverFor($user) !== null;

        /* «Рассмотрение» = NEW и этап согласантов: отдел закупок ещё работает с заявкой. */
        $inReview = in_array($status, [PurchaseStatus::NEW, PurchaseStatus::APPROVERS_PENDING], true);
        $hasApprovers = !$request->getApprovers()->isEmpty();
        $allApproversConfirmed = true;
        foreach ($request->getApprovers() as $approver) {
            if ($approver->getConfirmedAt() === null) {
                $allApproversConfirmed = false;
                break;
            }
        }
        $nextStatus = $status->nextExecutionStatus();
        // Конвейер ведёт отдел закупок; плательщик — только отметку «Оплачено».
        $canAdvance = $nextStatus !== null
            && ($isPurchase || ($isPayer && $status === PurchaseStatus::INVOICE_SENT));

        $canView = ($isDirector && in_array($status, PurchaseStatus::getDirectorVisible(), true))
            || ($isPurchase && in_array($status, PurchaseStatus::getPurchaseDepartmentVisible(), true))
            || ($isPayer && in_array($status, PurchaseStatus::getPayerVisible(), true))
            || $isOwner
            || $isApprover;

        return [
            'canEdit' => $isOwner && $status->isEditable(),
            'canDelete' => $isOwner && $status === PurchaseStatus::DRAFT,
            'canSubmit' => $isOwner && $status->isEditable(),
            'canSendToApprovers' => $isPurchase && $status === PurchaseStatus::NEW && $hasApprovers,
            'canSendToDirector' => $isPurchase && $allApproversConfirmed
                && (($status === PurchaseStatus::NEW && !$hasApprovers) || $status === PurchaseStatus::APPROVERS_DONE),
            'canClassify' => $isPurchase && $inReview,
            'canInvite' => $isPurchase && $inReview,
            'canConfirmApproval' => $isApprover && $status === PurchaseStatus::APPROVERS_PENDING,
            'canApprove' => $isDirector && $status === PurchaseStatus::CEO_APPROVE_PENDING,
            'canReject' => ($isPurchase && $inReview)
                || ($isDirector && in_array($status, [PurchaseStatus::CEO_APPROVE_PENDING, PurchaseStatus::CEO_APPROVED], true)),
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

    /** Отмена: автор — до взятия в работу; отдел закупок — на исполнении; директор — всегда (до финала). */
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
                PurchaseStatus::DRAFT, PurchaseStatus::NEW,
                PurchaseStatus::APPROVERS_PENDING, PurchaseStatus::APPROVERS_DONE,
                PurchaseStatus::CEO_APPROVE_PENDING, PurchaseStatus::CEO_APPROVED, PurchaseStatus::REJECTED,
            ], true);
        }
        if ($isPurchase) {
            return in_array($status, [
                PurchaseStatus::CONTRACT_PENDING, PurchaseStatus::INVOICE_SENT,
                PurchaseStatus::INVOICE_PAID, PurchaseStatus::DELIVERED,
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
