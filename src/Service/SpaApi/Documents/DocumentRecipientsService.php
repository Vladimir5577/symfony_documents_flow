<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentUserRecipient;
use App\Enum\Document\DocumentRecipientRole;
use App\Enum\Document\DocumentStatus;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class DocumentRecipientsService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<mixed> $ids
     *
     * @return list<int>
     */
    public function normalizeUserIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(static fn ($id) => (int) $id, $ids), static fn (int $id) => $id > 0)));
    }

    /**
     * @param list<int> $executorUserIds
     * @param list<int> $recipientUserIds
     */
    public function attachRecipients(Document $document, array $executorUserIds, array $recipientUserIds): void
    {
        $now = new \DateTimeImmutable();

        foreach ($executorUserIds as $userId) {
            $user = $this->userRepository->findActive($userId);
            if ($user === null || $user->getDeletedAt() !== null) {
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
            if ($user === null || $user->getDeletedAt() !== null) {
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

        if ($document->isPublished() && $document->getUserRecipients()->isEmpty()) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_NO_RECIPIENTS);
        }
    }

    /**
     * @param list<int> $executorUserIds
     * @param list<int> $recipientUserIds
     */
    public function replaceRecipients(Document $document, array $executorUserIds, array $recipientUserIds): bool
    {
        $activeUserIds = [];
        foreach ($this->normalizeUserIds(array_merge($executorUserIds, $recipientUserIds)) as $userId) {
            $user = $this->userRepository->findActive($userId);
            if ($user !== null && $user->getDeletedAt() === null) {
                $activeUserIds[] = $userId;
            }
        }
        $executorUserIds = array_values(array_intersect($this->normalizeUserIds($executorUserIds), $activeUserIds));
        $recipientUserIds = array_values(array_intersect($this->normalizeUserIds($recipientUserIds), $activeUserIds));

        if ($document->isPublished() && $executorUserIds === [] && $recipientUserIds === []) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_NO_RECIPIENTS);
        }

        $newRecipientKeys = [];
        foreach ($executorUserIds as $userId) {
            $newRecipientKeys[] = $userId . '|' . DocumentRecipientRole::EXECUTOR->value;
        }
        foreach ($recipientUserIds as $userId) {
            $newRecipientKeys[] = $userId . '|' . DocumentRecipientRole::RECIPIENT->value;
        }
        sort($newRecipientKeys);

        $currentRecipientKeys = [];
        foreach ($document->getUserRecipients() as $recipient) {
            $user = $recipient->getUser();
            if ($user !== null) {
                $currentRecipientKeys[] = $user->getId() . '|' . $recipient->getRole()->value;
            }
        }
        sort($currentRecipientKeys);

        if ($newRecipientKeys === $currentRecipientKeys) {
            return false;
        }

        foreach ($document->getUserRecipients()->toArray() as $recipient) {
            $userId = $recipient->getUser()?->getId();
            $key = $userId . '|' . $recipient->getRole()->value;
            if (!in_array($key, $newRecipientKeys, true)) {
                $document->removeUserRecipient($recipient);
                $this->entityManager->remove($recipient);
                continue;
            }

            if ($recipient->getRole() === DocumentRecipientRole::EXECUTOR) {
                $executorUserIds = array_values(array_diff($executorUserIds, [$userId]));
            } else {
                $recipientUserIds = array_values(array_diff($recipientUserIds, [$userId]));
            }
        }

        $this->attachRecipients($document, $executorUserIds, $recipientUserIds);
        $document->setUpdatedAt(new \DateTimeImmutable());

        return true;
    }
}
