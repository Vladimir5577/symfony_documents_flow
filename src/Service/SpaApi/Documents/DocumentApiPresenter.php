<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Entity\Document\Document;
use App\Entity\Document\DocumentComment;
use App\Entity\Document\DocumentHistory;
use App\Entity\Document\DocumentCommentFile;
use App\Entity\Document\File;
use App\Entity\Document\DocumentType;
use App\Entity\Document\DocumentUserRecipient;
use App\Entity\Organization\AbstractOrganization;
use App\Entity\Organization\Department;
use App\Entity\Organization\Filial;
use App\Entity\User\User;
use App\Enum\Document\DocumentRecipientRole;
use App\Enum\Document\DocumentStatus;
use App\Enum\Organization\OrganizationType;

final class DocumentApiPresenter
{
    public function presentType(DocumentType $type): array
    {
        return [
            'id' => $type->getId(),
            'name' => $type->getName(),
            'description' => $type->getDescription(),
        ];
    }

    public function presentUserBrief(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->getId(),
            'fullName' => $this->formatUserFullName($user),
            'profession' => $user->getWorker()?->getProfession(),
        ];
    }

    public function presentOrganizationBrief(?AbstractOrganization $organization): ?array
    {
        if ($organization === null) {
            return null;
        }

        return [
            'id' => $organization->getId(),
            'name' => $organization->getName(),
            'path' => $this->buildOrganizationPath($organization),
        ];
    }

    /**
     * @return array{id: int|null, name: string, type: string, children: list<array<string, mixed>>}
     */
    public function presentOrganizationTreeNode(AbstractOrganization $organization): array
    {
        return [
            'id' => $organization->getId(),
            'name' => $organization->getName(),
            'type' => $this->resolveOrganizationType($organization)->value,
            'children' => $organization->getChildOrganizations()
                ->map(fn (AbstractOrganization $child) => $this->presentOrganizationTreeNode($child))
                ->getValues(),
        ];
    }

    private function resolveOrganizationType(AbstractOrganization $organization): OrganizationType
    {
        if ($organization instanceof Filial) {
            return OrganizationType::FILIAL;
        }

        if ($organization instanceof Department) {
            return OrganizationType::DEPARTMENT;
        }

        return OrganizationType::ORGANIZATION;
    }

    public function presentDocumentListItem(Document $document): array
    {
        $status = $document->getStatus();

        return [
            'id' => $document->getId(),
            'name' => $document->getName(),
            'description' => $document->getDescription(),
            'status' => $status?->value,
            'statusLabel' => $status?->getLabel(),
            'isPublished' => $document->isPublished(),
            'createdAt' => $document->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $document->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'deadline' => $document->getDeadline()?->format('Y-m-d'),
            'documentType' => $document->getDocumentType() !== null
                ? $this->presentType($document->getDocumentType())
                : null,
            'organization' => $this->presentOrganizationBrief($document->getOrganizationCreator()),
            'createdBy' => $this->presentUserBrief($document->getCreatedBy()),
        ];
    }

    public function presentIncomingListItem(DocumentUserRecipient $recipient): array
    {
        $document = $recipient->getDocument();
        $status = $recipient->getStatus();

        return [
            'recipientId' => $recipient->getId(),
            'recipientStatus' => $status?->value,
            'recipientStatusLabel' => $status?->getLabel(),
            'role' => $recipient->getRole()->value,
            'document' => $document !== null ? $this->presentDocumentListItem($document) : null,
        ];
    }

    /**
     * @param list<DocumentUserRecipient> $recipients
     *
     * @return array{executors: list<array>, recipients: list<array>}
     */
    public function splitRecipientsByRole(array $recipients): array
    {
        $executors = [];
        $recipientRows = [];

        foreach ($recipients as $recipient) {
            $row = [
                'recipientId' => $recipient->getId(),
                'role' => $recipient->getRole()->value,
                'status' => $recipient->getStatus()?->value,
                'statusLabel' => $recipient->getStatus()?->getLabel(),
                'user' => $this->presentUserBrief($recipient->getUser()),
            ];

            if ($recipient->getRole() === DocumentRecipientRole::RECIPIENT) {
                $recipientRows[] = $row;
            } else {
                $executors[] = $row;
            }
        }

        return ['executors' => $executors, 'recipients' => $recipientRows];
    }

    /**
     * @return array<string, string>
     */
    public function presentStatusChoices(): array
    {
        return DocumentStatus::getChoices();
    }

    /**
     * @return array<string, string>
     */
    public function presentCreationStatusChoices(): array
    {
        return DocumentStatus::getCreationChoices();
    }

    public function presentPagination(int $page, int $limit, int $total): array
    {
        $totalPages = (int) max(1, ceil($total / $limit));

        return [
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_items' => $total,
            'items_per_page' => $limit,
        ];
    }

    public function formatUserFullName(User $user): string
    {
        $fullName = trim(sprintf(
            '%s %s %s',
            (string) $user->getLastname(),
            (string) $user->getFirstname(),
            (string) ($user->getPatronymic() ?? ''),
        ));

        return $fullName !== '' ? $fullName : (string) $user->getLogin();
    }

    public function buildOrganizationPath(?AbstractOrganization $organization): string
    {
        if ($organization === null) {
            return '—';
        }

        $parts = [];
        $current = $organization;

        while ($current !== null) {
            $name = trim((string) $current->getName());
            if ($name !== '') {
                $parts[] = $name;
            }
            $current = $current->getParent();
        }

        if ($parts === []) {
            return '—';
        }

        return implode(' / ', array_reverse($parts));
    }

    /**
     * @param iterable<DocumentComment> $comments
     *
     * @return list<array<string, mixed>>
     */
    public function presentDocumentComments(iterable $comments, User $viewer, bool $isAdmin): array
    {
        $rows = [];
        foreach ($comments as $comment) {
            if ($comment instanceof DocumentComment) {
                $rows[] = $this->presentDocumentComment($comment, $viewer, $isAdmin);
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentHistoryItem(DocumentHistory $item): array
    {
        $oldStatus = $item->getOldStatus();
        $newStatus = $item->getNewStatus();

        return [
            'id' => $item->getId(),
            'createdAt' => $item->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'action' => $item->getAction(),
            'changedBy' => $this->presentUserBrief($item->getUser()),
            'oldStatus' => $oldStatus?->value,
            'oldStatusLabel' => $oldStatus?->getLabel(),
            'newStatus' => $newStatus?->value,
            'newStatusLabel' => $newStatus?->getLabel(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentDocumentComment(DocumentComment $comment, User $viewer, bool $isAdmin): array
    {
        $author = $comment->getAuthor();
        $canManage = $isAdmin || $author->getId() === $viewer->getId();

        return [
            'id' => $comment->getId(),
            'body' => $comment->getBody(),
            'author' => $this->presentUserBrief($author),
            'authorId' => $author->getId(),
            'createdAt' => $comment->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $comment->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'canEdit' => $canManage,
            'canDelete' => $canManage,
            'files' => array_map(
                fn (DocumentCommentFile $file): array => $this->presentDocumentCommentFile($file),
                $comment->getFiles()->toArray(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentDocumentCommentFile(DocumentCommentFile $file): array
    {
        return [
            'id' => $file->getId(),
            'filename' => $file->getFilename() ?? '',
            'contentType' => $file->getContentType(),
            'size' => $file->getSizeBytes(),
            'createdAt' => $file->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentDocumentFile(File $file, ?int $size = null, ?string $contentType = null): array
    {
        return [
            'id' => $file->getId(),
            'filename' => $this->buildDocumentFileDisplayName($file),
            'contentType' => $contentType,
            'size' => $size,
            'createdAt' => null,
        ];
    }

    private function buildDocumentFileDisplayName(File $file): string
    {
        $path = $file->getFilePath();
        $title = $file->getTitle();
        if ($title !== null && $title !== '') {
            $ext = $path ? pathinfo($path, PATHINFO_EXTENSION) : '';
            if ($ext !== '') {
                return $title . '.' . $ext;
            }

            return $title;
        }

        return $path ?? 'file';
    }
}
