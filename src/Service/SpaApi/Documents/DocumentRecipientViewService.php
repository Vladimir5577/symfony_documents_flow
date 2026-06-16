<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Entity\Document\Document;
use App\Entity\Document\DocumentHistory;
use App\Entity\User\User;
use App\Enum\Document\DocumentStatus;
use Doctrine\ORM\EntityManagerInterface;

final class DocumentRecipientViewService
{
    public function __construct(
        private readonly DocumentAccessService $accessService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function markViewedIfNeeded(Document $document, User $currentUser): void
    {
        $userRecipient = $this->accessService->findUserRecipient($document, $currentUser);
        if ($userRecipient === null) {
            return;
        }

        if ($userRecipient->getStatus() !== DocumentStatus::NEW) {
            return;
        }

        $userRecipient->setStatus(DocumentStatus::VIEWED);
        $userRecipient->setUpdatedAt(new \DateTimeImmutable());

        $history = new DocumentHistory();
        $history->setDocument($document);
        $history->setUser($currentUser);
        $history->setAction('Пользователь просмотрел документ');
        $history->setOldStatus(DocumentStatus::NEW);
        $history->setNewStatus(DocumentStatus::VIEWED);
        $history->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($userRecipient);
        $this->entityManager->persist($history);
        $this->entityManager->flush();
    }
}
