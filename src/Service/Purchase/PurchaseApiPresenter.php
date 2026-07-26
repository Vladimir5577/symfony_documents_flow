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
            'downloadUrl' => sprintf(
                '/spa/api/purchases/%d/files/%d/download',
                $file->getPurchaseRequest()?->getId(),
                $file->getId(),
            ),
        ];
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
        $isOwner = $this->security->isGranted(UserRole::ROLE_MANAGER->value)
            && $user instanceof User
            && $request->getCreatedBy()?->getId() === $user->getId();
        $isApprover = $user instanceof User && $request->findApproverFor($user) !== null;

        $inReview = $status === PurchaseStatus::PENDING_REVIEW;
        $nextStatus = $status->nextExecutionStatus();
        $canAdvance = $isPurchase && $nextStatus !== null;

        $canView = $isDirector
            || ($isPurchase && in_array($status, PurchaseStatus::getPurchaseDepartmentVisible(), true))
            || $isOwner
            || $isApprover;

        return [
            'canEdit' => $isOwner && $status->isEditable(),
            'canDelete' => $isOwner && $status === PurchaseStatus::DRAFT,
            'canSubmit' => $isOwner && $status->isEditable(),
            'canSendToDirector' => $isPurchase && $inReview,
            'canClassify' => $isPurchase && $inReview,
            'canInvite' => $isPurchase && $inReview,
            'canConfirmApproval' => $isApprover && $inReview,
            'canApprove' => $isDirector
                && in_array($status, [PurchaseStatus::PENDING_REVIEW, PurchaseStatus::PENDING_APPROVAL], true),
            'canReject' => ($isPurchase && $inReview)
                || ($isDirector && in_array(
                    $status,
                    [PurchaseStatus::PENDING_REVIEW, PurchaseStatus::PENDING_APPROVAL, PurchaseStatus::APPROVED],
                    true,
                )),
            'canTake' => $isPurchase && $status === PurchaseStatus::APPROVED,
            'canAdvance' => $canAdvance,
            'nextStatus' => $canAdvance
                ? ['value' => $nextStatus->value, 'label' => $nextStatus->getLabel()]
                : null,
            'canConfirm' => $isOwner && $status === PurchaseStatus::DELIVERED,
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
                PurchaseStatus::DRAFT, PurchaseStatus::PENDING_REVIEW,
                PurchaseStatus::PENDING_APPROVAL, PurchaseStatus::APPROVED, PurchaseStatus::REJECTED,
            ], true);
        }
        if ($isPurchase) {
            return in_array($status, [
                PurchaseStatus::IN_PROGRESS, PurchaseStatus::AWAITING_PAYMENT,
                PurchaseStatus::PAID, PurchaseStatus::DELIVERED,
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
