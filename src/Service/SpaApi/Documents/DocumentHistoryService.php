<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentHistory;
use App\Entity\User\User;
use App\Repository\Document\DocumentHistoryRepository;
use App\Repository\User\UserRepository;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DocumentHistoryService
{
    public function __construct(
        private readonly DocumentHistoryRepository $historyRepository,
        private readonly UserRepository $userRepository,
        private readonly DocumentAccessService $accessService,
        private readonly DocumentApiPresenter $presenter,
    ) {
    }

    /**
     * @return array{
     *     document: array{id: int, name: string},
     *     historyUser: array<string, mixed>,
     *     items: list<array<string, mixed>>
     * }
     */
    public function getIncomingRecipientHistory(Document $document, User $viewer, int $historyUserId): array
    {
        $this->assertIncomingHistoryAccess($document, $viewer);

        return $this->buildHistoryResponse($document, $historyUserId);
    }

    /**
     * @return array{
     *     document: array{id: int, name: string},
     *     historyUser: array<string, mixed>,
     *     items: list<array<string, mixed>>
     * }
     */
    public function getOutgoingRecipientHistory(Document $document, User $viewer, int $historyUserId): array
    {
        $this->assertOutgoingHistoryAccess($document, $viewer, $historyUserId);

        return $this->buildHistoryResponse($document, $historyUserId);
    }

    private function assertIncomingHistoryAccess(Document $document, User $viewer): void
    {
        if (!$this->accessService->canViewDocument($document, $viewer)) {
            throw new AccessDeniedHttpException(SpaApiError::ACCESS_DENIED);
        }
    }

    private function assertOutgoingHistoryAccess(Document $document, User $viewer, int $historyUserId): void
    {
        if (!$this->accessService->canEditOutgoingDocument($document, $viewer)
            && !$this->accessService->isAdmin()) {
            throw new AccessDeniedHttpException(SpaApiError::ACCESS_DENIED);
        }

        if (!$this->isDocumentParticipantUser($document, $historyUserId)) {
            throw new AccessDeniedHttpException(SpaApiError::ACCESS_DENIED);
        }
    }

    /**
     * @return array{
     *     document: array{id: int, name: string},
     *     historyUser: array<string, mixed>,
     *     items: list<array<string, mixed>>
     * }
     */
    private function buildHistoryResponse(Document $document, int $historyUserId): array
    {
        $historyUser = $this->userRepository->find($historyUserId);
        if ($historyUser === null) {
            throw new NotFoundHttpException(SpaApiError::USER_NOT_FOUND);
        }

        $documentId = $document->getId();
        if ($documentId === null) {
            throw new NotFoundHttpException(SpaApiError::DOCUMENT_NOT_FOUND);
        }

        $historyItems = $this->historyRepository->findByDocumentAndUserOrderByCreatedAtDesc(
            $documentId,
            $historyUserId,
        );

        return [
            'document' => [
                'id' => $documentId,
                'name' => (string) $document->getName(),
            ],
            'historyUser' => $this->presenter->presentUserBrief($historyUser),
            'items' => array_map(
                fn (DocumentHistory $item): array => $this->presenter->presentHistoryItem($item),
                $historyItems,
            ),
        ];
    }

    private function isDocumentParticipantUser(Document $document, int $userId): bool
    {
        foreach ($document->getUserRecipients() as $recipient) {
            if ($recipient->getUser()?->getId() === $userId) {
                return true;
            }
        }

        return false;
    }
}
