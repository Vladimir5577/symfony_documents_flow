<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Organization\AbstractOrganization;
use App\Entity\User\User;
use App\Enum\Document\DocumentRecipientRole;
use App\Enum\Document\DocumentStatus;
use App\Enum\Document\SignatureLevel;
use App\Repository\Organization\OrganizationRepository;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class DocumentUpdateService
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly DocumentAccessService $accessService,
        private readonly DocumentRecipientsService $recipientsService,
        private readonly NotificationService $notificationService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param array{
     *     name?: string,
     *     description?: string|null,
     *     organizationId?: int|null,
     *     status?: string,
     *     deadline?: string|null,
     *     isPublished?: bool,
     *     signatureLevel?: string|null,
     *     signers?: list<array{userId: int, order: int}>,
     * } $payload
     */
    public function update(Document $document, array $payload, User $currentUser): Document
    {
        if (!$this->accessService->canEditOutgoingDocument($document, $currentUser)) {
            throw new AccessDeniedHttpException(SpaApiError::ACCESS_DENIED);
        }

        $name = trim((string) ($payload['name'] ?? $document->getName() ?? ''));
        if ($name === '') {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_NAME_REQUIRED);
        }

        $organizationId = (int) ($payload['organizationId'] ?? $document->getOrganizationCreator()?->getId() ?? 0);
        if ($organizationId <= 0) {
            throw new BadRequestHttpException(SpaApiError::ORGANIZATION_REQUIRED);
        }

        $organization = $this->organizationRepository->find($organizationId);
        if (!$organization instanceof AbstractOrganization) {
            throw new NotFoundHttpException(SpaApiError::ORGANIZATION_NOT_FOUND);
        }

        $statusStr = trim((string) ($payload['status'] ?? $document->getStatus()?->value ?? ''));
        $status = DocumentStatus::tryFrom($statusStr);
        if ($status === null) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_INVALID_STATUS);
        }

        $deadlineStr = trim((string) ($payload['deadline'] ?? ''));
        $deadline = $document->getDeadline();
        if ($deadlineStr !== '') {
            $parsed = \DateTime::createFromFormat('Y-m-d', $deadlineStr);
            if ($parsed === false) {
                throw new BadRequestHttpException(SpaApiError::DOCUMENT_INVALID_DEADLINE);
            }
            $deadline = $parsed;
        } elseif (array_key_exists('deadline', $payload) && $payload['deadline'] === null) {
            $deadline = null;
        }

        $wantsPublish = (bool) ($payload['isPublished'] ?? $document->isPublished());
        if ($wantsPublish && $status === DocumentStatus::DRAFT) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_CANNOT_PUBLISH_DRAFT);
        }
        if ($wantsPublish && $document->getUserRecipients()->isEmpty()) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_NO_RECIPIENTS);
        }

        $signatureLevel = array_key_exists('signatureLevel', $payload)
            ? $this->resolveSignatureLevel($payload)
            : $document->getSignatureLevel();
        $signers = array_key_exists('signers', $payload)
            ? $this->recipientsService->normalizeSigners($payload['signers'])
            : $this->currentSigners($document);

        if ($signers !== [] && $signatureLevel === null) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_SIGNATURE_LEVEL_REQUIRED);
        }
        if ($signatureLevel !== null && $signers === []) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_SIGNERS_REQUIRED);
        }

        if ($signatureLevel !== $document->getSignatureLevel()) {
            $this->recipientsService->assertSigningNotLocked($document);
            $document->setSignatureLevel($signatureLevel);
        }
        if (array_key_exists('signers', $payload)) {
            // no-op при неизменном составе; при изменении в ON_SIGNING/SIGNED бросает document_signing_locked
            $this->recipientsService->replaceSigners($document, $signers);
        }

        $wasAlreadyPublished = $document->isPublished();

        $description = trim((string) ($payload['description'] ?? $document->getDescription() ?? ''));
        $document->setName($name);
        $document->setDescription($description !== '' ? $description : null);
        $document->setOrganizationCreator($organization);
        $document->setStatus($status);
        $document->setDeadline($deadline);
        $document->setIsPublished($wantsPublish);
        $document->setUpdatedAt(new \DateTimeImmutable());

        $errors = $this->validator->validate($document);
        if (count($errors) > 0) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_VALIDATION_FAILED);
        }

        $this->entityManager->flush();

        if (!$wasAlreadyPublished && $wantsPublish) {
            $this->notifyPublished($document);
        }

        return $document;
    }

    /**
     * @param array{signatureLevel?: string|null} $payload
     */
    private function resolveSignatureLevel(array $payload): ?SignatureLevel
    {
        $value = $payload['signatureLevel'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        $level = is_string($value) ? SignatureLevel::tryFrom($value) : null;
        if ($level === null) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_INVALID_SIGNATURE_LEVEL);
        }

        return $level;
    }

    /**
     * @return list<array{userId: int, order: int}>
     */
    private function currentSigners(Document $document): array
    {
        $signers = [];
        foreach ($document->getUserRecipients() as $recipient) {
            $user = $recipient->getUser();
            if ($recipient->getRole() === DocumentRecipientRole::SIGNER && $user !== null) {
                $signers[] = ['userId' => (int) $user->getId(), 'order' => (int) $recipient->getSigningOrder()];
            }
        }

        return $signers;
    }

    private function notifyPublished(Document $document): void
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
