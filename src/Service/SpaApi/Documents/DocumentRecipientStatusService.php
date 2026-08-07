<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentHistory;
use App\Entity\User\User;
use App\Enum\Document\DocumentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class DocumentRecipientStatusService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentAccessService $accessService,
        private readonly DocumentNotifier $documentNotifier,
    ) {
    }

    /**
     * @param array{status?: string} $payload
     */
    public function updateStatus(Document $document, array $payload, User $currentUser): Document
    {
        $userRecipient = $this->accessService->findUserRecipient($document, $currentUser);
        if ($userRecipient === null) {
            throw new AccessDeniedHttpException(SpaApiError::ACCESS_DENIED);
        }

        $statusValue = trim((string) ($payload['status'] ?? ''));
        if ($statusValue === '') {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_INVALID_STATUS);
        }

        try {
            $status = DocumentStatus::from($statusValue);
        } catch (\ValueError) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_INVALID_STATUS);
        }

        if (!in_array($status, DocumentStatus::getReceiverAllowedStatuses(), true)) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_INVALID_STATUS);
        }

        $oldStatus = $userRecipient->getStatus();
        if ($oldStatus === $status) {
            return $document;
        }

        $userRecipient->setStatus($status);
        $userRecipient->setUpdatedAt(new \DateTimeImmutable());

        $history = new DocumentHistory();
        $history->setDocument($document);
        $history->setUser($currentUser);
        $history->setAction('Изменение статуса получателя');
        $history->setOldStatus($oldStatus ?? DocumentStatus::NEW);
        $history->setNewStatus($status);
        $history->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($history);
        $this->entityManager->flush();

        $this->notifyStatusChange($document, $currentUser, $status);

        return $document;
    }

    private function notifyStatusChange(Document $document, User $currentUser, DocumentStatus $status): void
    {
        // Кому показывать: автору документа и остальным участникам, кроме того,
        // кто статус и поменял. Ссылку и текст соберёт DocumentNotifier — автор
        // смотрит документ как исходящий, участники как входящий.
        $recipientsById = [];

        $creator = $document->getCreatedBy();
        if ($creator !== null && $creator->getId() !== $currentUser->getId()) {
            $recipientsById[$creator->getId()] = $creator;
        }

        foreach ($document->getUserRecipients() as $recipient) {
            $participant = $recipient->getUser();
            if ($participant === null || $participant->getId() === $currentUser->getId()) {
                continue;
            }
            $recipientsById[$participant->getId()] = $participant;
        }

        $this->documentNotifier->notifyStatusChanged(
            $document,
            $currentUser,
            $status,
            array_values($recipientsById),
        );
    }
}
