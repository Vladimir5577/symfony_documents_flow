<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Entity\Document\Document;
use App\Entity\User\User;
use App\Enum\DocumentStatus;

final class DocumentDetailResponseBuilder
{
    public function __construct(
        private readonly DocumentApiPresenter $presenter,
        private readonly DocumentAccessService $accessService,
        private readonly DocumentAttachmentService $attachmentService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildOutgoingDetail(Document $document, User $user): array
    {
        $split = $this->presenter->splitRecipientsByRole($document->getUserRecipients()->toArray());
        $statusChoices = $this->presenter->presentCreationStatusChoices();

        return [
            'document' => $this->presenter->presentDocumentListItem($document),
            'executors' => $split['executors'],
            'recipients' => $split['recipients'],
            'files' => $this->attachmentService->presentAttachments($document->getFiles()),
            'permissions' => $this->accessService->presentPermissions($document, $user),
            'allowedDocumentStatuses' => $this->presenter->presentStatusChoiceDtos($statusChoices),
            'statusChoices' => $statusChoices,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildIncomingDetail(Document $document, User $user): array
    {
        $split = $this->presenter->splitRecipientsByRole($document->getUserRecipients()->toArray());
        $userRecipient = $this->accessService->findUserRecipient($document, $user);

        return [
            'document' => $this->presenter->presentDocumentListItem($document),
            'executors' => $split['executors'],
            'recipients' => $split['recipients'],
            'files' => $this->attachmentService->presentAttachments($document->getFiles()),
            'userRecipient' => $userRecipient !== null ? [
                'recipientId' => $userRecipient->getId(),
                'status' => $userRecipient->getStatus()?->value,
                'statusLabel' => $userRecipient->getStatus()?->getLabel(),
            ] : null,
            'allowedRecipientStatuses' => array_map(
                static fn (DocumentStatus $case) => [
                    'value' => $case->value,
                    'label' => $case->getLabel(),
                ],
                DocumentStatus::getReceiverAllowedStatuses(),
            ),
            'permissions' => $this->accessService->presentPermissions($document, $user),
        ];
    }
}
