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
            if ($user !== null && $recipient->getRole() !== DocumentRecipientRole::SIGNER) {
                $currentRecipientKeys[] = $user->getId() . '|' . $recipient->getRole()->value;
            }
        }
        sort($currentRecipientKeys);

        if ($newRecipientKeys === $currentRecipientKeys) {
            return false;
        }

        foreach ($document->getUserRecipients()->toArray() as $recipient) {
            if ($recipient->getRole() === DocumentRecipientRole::SIGNER) {
                continue;
            }
            $document->getUserRecipients()->removeElement($recipient);
            $this->entityManager->remove($recipient);
        }
        $this->entityManager->flush();

        $this->attachRecipients($document, $executorUserIds, $recipientUserIds);
        $document->setUpdatedAt(new \DateTimeImmutable());

        return true;
    }

    /**
     * @param mixed $signers raw `signers` payload: [{userId, order}]
     *
     * @return list<array{userId: int, order: int}>
     */
    public function normalizeSigners(mixed $signers): array
    {
        if (!is_array($signers)) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_INVALID_SIGNERS);
        }

        $normalized = [];
        foreach ($signers as $signer) {
            if (!is_array($signer) || !is_numeric($signer['userId'] ?? null) || !is_numeric($signer['order'] ?? null)) {
                throw new BadRequestHttpException(SpaApiError::DOCUMENT_INVALID_SIGNERS);
            }

            $userId = (int) $signer['userId'];
            $order = (int) $signer['order'];
            if ($userId <= 0 || $order < 1) {
                throw new BadRequestHttpException(SpaApiError::DOCUMENT_INVALID_SIGNERS);
            }

            $normalized[$userId] = ['userId' => $userId, 'order' => $order];
        }

        return array_values($normalized);
    }

    /**
     * @param list<array{userId: int, order: int}> $signers
     */
    public function attachSigners(Document $document, array $signers): void
    {
        $now = new \DateTimeImmutable();

        foreach ($signers as $signer) {
            $user = $this->userRepository->findActive($signer['userId']);
            if ($user === null) {
                // Подписанты, в отличие от обычных получателей, строгие: молчаливый пропуск
                // оставил бы документ с меньшим числом подписей, чем задумал автор.
                throw new BadRequestHttpException(SpaApiError::DOCUMENT_SIGNER_NOT_FOUND);
            }

            $recipient = new DocumentUserRecipient();
            $recipient->setDocument($document);
            $recipient->setUser($user);
            $recipient->setRole(DocumentRecipientRole::SIGNER);
            $recipient->setStatus(DocumentStatus::NEW);
            $recipient->setSigningOrder($signer['order']);
            $recipient->setCreatedAt($now);
            $recipient->setUpdatedAt($now);
            $document->addUserRecipient($recipient);
            $this->entityManager->persist($recipient);
        }
    }

    /**
     * @param list<array{userId: int, order: int}> $signers
     */
    public function replaceSigners(Document $document, array $signers): bool
    {
        $newSignerKeys = [];
        foreach ($signers as $signer) {
            $newSignerKeys[] = $signer['userId'] . '|' . $signer['order'];
        }
        sort($newSignerKeys);

        $currentSigners = [];
        $currentSignerKeys = [];
        foreach ($document->getUserRecipients() as $recipient) {
            if ($recipient->getRole() !== DocumentRecipientRole::SIGNER) {
                continue;
            }
            $currentSigners[] = $recipient;
            $user = $recipient->getUser();
            if ($user !== null) {
                $currentSignerKeys[] = $user->getId() . '|' . $recipient->getSigningOrder();
            }
        }
        sort($currentSignerKeys);

        if ($newSignerKeys === $currentSignerKeys) {
            return false;
        }

        $this->assertSigningNotLocked($document);

        // Проверяем существование всех новых подписантов ДО удаления старых:
        // иначе ошибка на attachSigners оставила бы документ вовсе без подписантов.
        foreach ($signers as $signer) {
            if ($this->userRepository->findActive($signer['userId']) === null) {
                throw new BadRequestHttpException(SpaApiError::DOCUMENT_SIGNER_NOT_FOUND);
            }
        }

        foreach ($currentSigners as $recipient) {
            $document->getUserRecipients()->removeElement($recipient);
            $this->entityManager->remove($recipient);
        }
        $this->entityManager->flush();

        $this->attachSigners($document, $signers);
        $document->setUpdatedAt(new \DateTimeImmutable());

        return true;
    }

    public function assertSigningNotLocked(Document $document): void
    {
        if (in_array($document->getStatus(), [DocumentStatus::ON_SIGNING, DocumentStatus::SIGNED], true)) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_SIGNING_LOCKED);
        }
    }
}
