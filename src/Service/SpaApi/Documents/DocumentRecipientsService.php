<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Entity\Document\Document;
use App\Entity\Document\DocumentUserRecipient;
use App\Enum\Document\DocumentRecipientRole;
use App\Enum\Document\DocumentStatus;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

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

    /**
     * @param list<int> $executorUserIds
     * @param list<int> $recipientUserIds
     */
    public function replaceRecipients(Document $document, array $executorUserIds, array $recipientUserIds): bool
    {
        $executorUserIds = $this->normalizeUserIds($executorUserIds);
        $recipientUserIds = $this->normalizeUserIds($recipientUserIds);

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
            $this->entityManager->remove($recipient);
        }
        $document->getUserRecipients()->clear();
        $this->entityManager->flush();

        $this->attachRecipients($document, $executorUserIds, $recipientUserIds);
        $document->setUpdatedAt(new \DateTimeImmutable());

        return true;
    }
}
