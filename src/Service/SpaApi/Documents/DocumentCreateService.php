<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentType;
use App\Entity\Document\DocumentUserRecipient;
use App\Entity\Organization\AbstractOrganization;
use App\Entity\User\User;
use App\Enum\DocumentRecipientRole;
use App\Enum\DocumentStatus;
use App\Repository\Document\DocumentTypeRepository;
use App\Repository\Organization\OrganizationRepository;
use App\Repository\User\UserRepository;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class DocumentCreateService
{
    public function __construct(
        private readonly DocumentTypeRepository $documentTypeRepository,
        private readonly OrganizationRepository $organizationRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly NotificationService $notificationService,
        private readonly DocumentAccessService $documentAccessService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param array{
     *     documentTypeId?: int,
     *     name?: string,
     *     description?: string|null,
     *     organizationId?: int|null,
     *     status?: string,
     *     isPublished?: bool,
     *     deadline?: string|null,
     *     executorUserIds?: list<int>,
     *     recipientUserIds?: list<int>,
     * } $payload
     */
    public function create(array $payload, User $currentUser): Document
    {
        $documentTypeId = (int) ($payload['documentTypeId'] ?? 0);
        $documentType = $this->documentTypeRepository->find($documentTypeId);
        if (!$documentType instanceof DocumentType) {
            throw new NotFoundHttpException(SpaApiError::DOCUMENT_TYPE_NOT_FOUND);
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_NAME_REQUIRED);
        }

        $organization = $this->resolveOrganization($payload, $currentUser);

        $status = $this->resolveStatus($payload);
        $wantsPublish = (bool) ($payload['isPublished'] ?? false);

        if ($wantsPublish && $status === DocumentStatus::DRAFT) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_CANNOT_PUBLISH_DRAFT);
        }

        $executorUserIds = $this->normalizeUserIds($payload['executorUserIds'] ?? []);
        $recipientUserIds = $this->normalizeUserIds($payload['recipientUserIds'] ?? []);

        if ($wantsPublish && $executorUserIds === [] && $recipientUserIds === []) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_NO_RECIPIENTS);
        }

        $document = new Document();
        $document->setName($name);
        $description = trim((string) ($payload['description'] ?? ''));
        $document->setDescription($description !== '' ? $description : null);
        $document->setOrganizationCreator($organization);
        $document->setDocumentType($documentType);
        $document->setStatus($status);
        $document->setCreatedBy($currentUser);
        $document->setIsPublished($wantsPublish);

        $deadlineStr = trim((string) ($payload['deadline'] ?? ''));
        if ($deadlineStr !== '') {
            $deadline = \DateTime::createFromFormat('Y-m-d', $deadlineStr);
            if ($deadline === false) {
                throw new BadRequestHttpException(SpaApiError::DOCUMENT_INVALID_DEADLINE);
            }
            $document->setDeadline($deadline);
        }

        $errors = $this->validator->validate($document);
        if (count($errors) > 0) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_VALIDATION_FAILED);
        }

        $this->entityManager->persist($document);
        $this->attachRecipients($document, $executorUserIds, $recipientUserIds);
        $this->entityManager->flush();

        if ($wantsPublish) {
            $this->notifyRecipients($document);
        }

        return $document;
    }

    /**
     * @param array{organizationId?: int|null} $payload
     */
    private function resolveOrganization(array $payload, User $currentUser): AbstractOrganization
    {
        if ($this->documentAccessService->isAdmin()) {
            $organizationId = (int) ($payload['organizationId'] ?? 0);
            if ($organizationId <= 0) {
                throw new BadRequestHttpException(SpaApiError::ORGANIZATION_REQUIRED);
            }
            $organization = $this->organizationRepository->find($organizationId);
            if (!$organization instanceof AbstractOrganization) {
                throw new NotFoundHttpException(SpaApiError::ORGANIZATION_NOT_FOUND);
            }

            return $organization;
        }

        $userOrganization = $currentUser->getOrganization();
        if ($userOrganization === null) {
            throw new BadRequestHttpException(SpaApiError::ORGANIZATION_REQUIRED);
        }

        return $userOrganization;
    }

    /**
     * @param array{status?: string} $payload
     */
    private function resolveStatus(array $payload): DocumentStatus
    {
        $statusStr = trim((string) ($payload['status'] ?? ''));
        if ($statusStr === '') {
            return DocumentStatus::DRAFT;
        }

        try {
            return DocumentStatus::from($statusStr);
        } catch (\ValueError) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_INVALID_STATUS);
        }
    }

    /**
     * @param list<mixed> $ids
     *
     * @return list<int>
     */
    private function normalizeUserIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(static fn ($id) => (int) $id, $ids), static fn (int $id) => $id > 0)));
    }

    /**
     * @param list<int> $executorUserIds
     * @param list<int> $recipientUserIds
     */
    private function attachRecipients(Document $document, array $executorUserIds, array $recipientUserIds): void
    {
        $now = new \DateTimeImmutable();

        foreach ($executorUserIds as $userId) {
            $user = $this->userRepository->findActive($userId);
            if ($user === null) {
                continue;
            }

            $recipient = new DocumentUserRecipient();
            $recipient->setDocument($document);
            $recipient->setUser($user);
            $recipient->setRole(DocumentRecipientRole::EXECUTOR);
            $recipient->setStatus(DocumentStatus::NEW);
            $recipient->setCreatedAt($now);
            $recipient->setUpdatedAt($now);
            $document->addUserRecipient($recipient);
            $this->entityManager->persist($recipient);
        }

        foreach ($recipientUserIds as $userId) {
            $user = $this->userRepository->findActive($userId);
            if ($user === null) {
                continue;
            }

            $recipient = new DocumentUserRecipient();
            $recipient->setDocument($document);
            $recipient->setUser($user);
            $recipient->setRole(DocumentRecipientRole::RECIPIENT);
            $recipient->setStatus(DocumentStatus::NEW);
            $recipient->setCreatedAt($now);
            $recipient->setUpdatedAt($now);
            $document->addUserRecipient($recipient);
            $this->entityManager->persist($recipient);
        }
    }

    private function notifyRecipients(Document $document): void
    {
        $recipientsById = [];
        foreach ($document->getUserRecipients() as $recipient) {
            $user = $recipient->getUser();
            if ($user !== null) {
                $recipientsById[$user->getId()] = $user;
            }
        }

        $recipients = array_values($recipientsById);
        if ($recipients === []) {
            return;
        }

        $documentId = $document->getId();
        if ($documentId === null) {
            return;
        }

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
