<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\User\User;
use App\Enum\Document\DocumentStatus;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class DocumentPublishService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentAccessService $accessService,
        private readonly NotificationService $notificationService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function publish(Document $document, User $currentUser): Document
    {
        if (!$this->accessService->canEditOutgoingDocument($document, $currentUser)) {
            throw new AccessDeniedHttpException(SpaApiError::ACCESS_DENIED);
        }

        if ($document->getStatus() === DocumentStatus::DRAFT) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_CANNOT_PUBLISH_DRAFT);
        }

        if ($document->getUserRecipients()->isEmpty()) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_NO_RECIPIENTS);
        }

        if ($document->isPublished()) {
            return $document;
        }

        $document->setIsPublished(true);
        $document->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $recipientsById = [];
        foreach ($document->getUserRecipients() as $recipient) {
            $user = $recipient->getUser();
            if ($user !== null) {
                $recipientsById[$user->getId()] = $user;
            }
        }

        $recipients = array_values($recipientsById);
        if ($recipients !== []) {
            $documentId = $document->getId();
            if ($documentId !== null) {
                $link = $this->urlGenerator->generate(
                    'app_view_incoming_document',
                    ['id' => $documentId],
                    UrlGeneratorInterface::ABSOLUTE_PATH,
                );
                $this->notificationService->notifyNewIncomingDocumentToRecipients(
                    $recipients,
                    (string) $document->getName(),
                    $link,
                );
            }
        }

        return $document;
    }
}
