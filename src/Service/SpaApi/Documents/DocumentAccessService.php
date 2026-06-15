<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Entity\Document\Document;
use App\Entity\Document\DocumentComment;
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

    public function canCommentDocument(Document $document, User $user): bool
    {
        return $this->canViewDocument($document, $user);
    }

    public function canManageComment(DocumentComment $comment, User $user): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $comment->getAuthor()->getId() === $user->getId();
    }

    /**
     * @return array{
     *     canView: bool,
     *     canEdit: bool,
     *     canPublish: bool,
     *     canChangeRecipientStatus: bool,
     *     canComment: bool,
     *     canViewRecipientHistory: bool
     * }
     */
    public function presentPermissions(Document $document, User $user): array
    {
        $recipient = $this->findUserRecipient($document, $user);
        $canEdit = $this->canEditOutgoingDocument($document, $user);
        $canView = $this->canViewDocument($document, $user);

        return [
            'canView' => $canView,
            'canEdit' => $canEdit,
            'canPublish' => $canEdit
                && $document->getStatus() !== null
                && !$document->isPublished()
                && !$document->getUserRecipients()->isEmpty(),
            'canChangeRecipientStatus' => $recipient !== null,
            'canComment' => $this->canCommentDocument($document, $user),
            'canViewRecipientHistory' => $canView || $canEdit,
        ];
    }
}
