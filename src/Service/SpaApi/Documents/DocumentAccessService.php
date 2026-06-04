<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Entity\Document\Document;
use App\Entity\Document\DocumentUserRecipient;
use App\Entity\User\User;
use Symfony\Bundle\SecurityBundle\Security;

final class DocumentAccessService
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->security->isGranted('ROLE_ADMIN');
    }

    public function canViewDocument(Document $document, User $user): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if ($document->getCreatedBy()?->getId() === $user->getId()) {
            return true;
        }

        return $this->findUserRecipient($document, $user) !== null;
    }

    public function canEditOutgoingDocument(Document $document, User $user): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $document->getCreatedBy()?->getId() === $user->getId();
    }

    public function findUserRecipient(Document $document, User $user): ?DocumentUserRecipient
    {
        foreach ($document->getUserRecipients() as $recipient) {
            if ($recipient->getUser()?->getId() === $user->getId()) {
                return $recipient;
            }
        }

        return null;
    }

    /**
     * @return array{canView: bool, canEdit: bool, canPublish: bool, canChangeRecipientStatus: bool}
     */
    public function presentPermissions(Document $document, User $user): array
    {
        $recipient = $this->findUserRecipient($document, $user);
        $canEdit = $this->canEditOutgoingDocument($document, $user);

        return [
            'canView' => $this->canViewDocument($document, $user),
            'canEdit' => $canEdit,
            'canPublish' => $canEdit
                && $document->getStatus() !== null
                && !$document->isPublished()
                && !$document->getUserRecipients()->isEmpty(),
            'canChangeRecipientStatus' => $recipient !== null,
        ];
    }
}
