<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentHistory;
use App\Entity\User\User;
use App\Enum\DocumentStatus;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class DocumentRecipientStatusService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentAccessService $accessService,
        private readonly NotificationService $notificationService,
        private readonly UrlGeneratorInterface $urlGenerator,
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
        $authorFullName = trim(sprintf(
            '%s %s %s',
            (string) $currentUser->getLastname(),
            (string) $currentUser->getFirstname(),
            (string) ($currentUser->getPatronymic() ?? ''),
        ));
        if ($authorFullName === '') {
            $authorFullName = 'Пользователь';
        }

        $notificationTitle = sprintf(
            '%s изменил статус документа «%s» на «%s».',
            $authorFullName,
            $document->getName(),
            $status->getLabel(),
        );

        $documentId = $document->getId();
        if ($documentId === null) {
            return;
        }

        $recipientsById = [];
        $creator = $document->getCreatedBy();
        if ($creator !== null && $creator->getId() !== $currentUser->getId()) {
            $recipientsById[$creator->getId()] = [
                'user' => $creator,
                'link' => $this->urlGenerator->generate(
                    'app_view_outgoing_document',
                    ['id' => $documentId],
                    UrlGeneratorInterface::ABSOLUTE_PATH,
                ),
            ];
        }

        foreach ($document->getUserRecipients() as $recipient) {
            $participant = $recipient->getUser();
            if ($participant === null || $participant->getId() === $currentUser->getId()) {
                continue;
            }

            $recipientsById[$participant->getId()] = [
                'user' => $participant,
                'link' => $this->urlGenerator->generate(
                    'app_view_incoming_document',
                    ['id' => $documentId],
                    UrlGeneratorInterface::ABSOLUTE_PATH,
                ),
            ];
        }

        foreach ($recipientsById as $item) {
            $this->notificationService->notifyGeneric($item['user'], $notificationTitle, $item['link']);
        }
    }
}
