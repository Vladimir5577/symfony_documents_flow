<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestComment;
use App\Entity\Purchase\PurchaseRequestFile;
use App\Entity\Purchase\PurchaseRequestHistory;
use App\Entity\Purchase\PurchaseRequestItem;
use App\Entity\User\User;
use App\Security\Voter\PurchaseRequestVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Форматирование заявок закупок для SpaApi.
 */
final class PurchaseApiPresenter
{
    public function __construct(
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function presentListItem(PurchaseRequest $request): array
    {
        $status = $request->getStatus();
        $priority = $request->getPriority();

        return [
            'id' => $request->getId(),
            'title' => $request->getTitle(),
            'status' => ['value' => $status->value, 'label' => $status->getLabel()],
            'priority' => ['value' => $priority->value, 'label' => $priority->getLabel()],
            'organization' => [
                'id' => $request->getOrganization()?->getId(),
                'name' => $request->getOrganization()?->getName(),
            ],
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
     *
     * @return array<string, mixed>
     */
    private function presentActions(PurchaseRequest $request): array
    {
        $granted = fn (string $attribute): bool => $this->authorizationChecker->isGranted($attribute, $request);
        $nextStatus = $request->getStatus()->nextExecutionStatus();

        return [
            'canEdit' => $granted(PurchaseRequestVoter::EDIT),
            'canDelete' => $granted(PurchaseRequestVoter::DELETE),
            'canSubmit' => $granted(PurchaseRequestVoter::SUBMIT),
            'canApprove' => $granted(PurchaseRequestVoter::APPROVE),
            'canReject' => $granted(PurchaseRequestVoter::REJECT),
            'canTake' => $granted(PurchaseRequestVoter::TAKE),
            'canAdvance' => $granted(PurchaseRequestVoter::ADVANCE),
            'nextStatus' => $granted(PurchaseRequestVoter::ADVANCE) && $nextStatus !== null
                ? ['value' => $nextStatus->value, 'label' => $nextStatus->getLabel()]
                : null,
            'canConfirm' => $granted(PurchaseRequestVoter::CONFIRM),
            'canCancel' => $granted(PurchaseRequestVoter::CANCEL),
            'canSetPriority' => $granted(PurchaseRequestVoter::SET_PRIORITY),
            'canComment' => $granted(PurchaseRequestVoter::COMMENT),
        ];
    }

    /**
     * @return array{id: int|null, name: string}|null
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
        ];
    }
}
