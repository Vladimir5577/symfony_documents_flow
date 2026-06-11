<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentComment;
use App\Entity\Document\DocumentCommentFile;
use App\Entity\User\User;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class DocumentCommentService
{
    private const MAX_FILE_SIZE_BYTES = 25 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentApiPresenter $presenter,
        private readonly Security $security,
        private readonly NotificationService $notificationService,
        #[Autowire('%private_upload_dir_documents_comments%')]
        private readonly string $commentsUploadDir,
    ) {
    }

    /**
     * @param list<UploadedFile> $uploadedFiles
     */
    public function create(Document $document, User $user, string $body, array $uploadedFiles): DocumentComment
    {
        $body = trim($body);
        $uploadedFiles = array_values(array_filter(
            $uploadedFiles,
            static fn ($file) => $file instanceof UploadedFile,
        ));

        if ($body === '' && $uploadedFiles === []) {
            throw new BadRequestHttpException('Комментарий не может быть пустым.');
        }

        foreach ($uploadedFiles as $uploaded) {
            if (!$uploaded->isValid()) {
                throw new BadRequestHttpException(
                    $uploaded->getErrorMessage() ?: 'Не удалось загрузить файл.',
                );
            }

            $size = $uploaded->getSize();
            if ($size !== false && $size > self::MAX_FILE_SIZE_BYTES) {
                throw new BadRequestHttpException(
                    sprintf('Файл «%s» превышает 25 МБ.', $uploaded->getClientOriginalName()),
                );
            }
        }

        $comment = new DocumentComment();
        $comment->setDocument($document);
        $comment->setAuthor($user);
        $comment->setBody($body);
        $document->addComment($comment);

        foreach ($uploadedFiles as $uploaded) {
            $attachment = new DocumentCommentFile();
            $attachment->setAuthor($user);
            $attachment->setComment($comment);
            $attachment->setFile($uploaded);
            $comment->addFile($attachment);
            $this->entityManager->persist($attachment);
        }

        $document->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($comment);
        $this->entityManager->flush();

        $this->sendCommentNotifications($document, $user);

        return $comment;
    }

    public function update(DocumentComment $comment, User $user, string $body): DocumentComment
    {
        $this->assertCanManageComment($comment, $user);

        $body = trim($body);
        if ($body === '' && $comment->getFiles()->count() === 0) {
            throw new BadRequestHttpException('Комментарий не может быть пустым.');
        }

        $comment->setBody($body);
        $comment->getDocument()->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $comment;
    }

    public function delete(DocumentComment $comment, User $user): void
    {
        $this->assertCanManageComment($comment, $user);

        $document = $comment->getDocument();
        $document->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->remove($comment);
        $this->entityManager->flush();
    }

    public function resolveAbsolutePath(DocumentCommentFile $file): ?string
    {
        $storageKey = $file->getStorageKey();
        if ($storageKey === null || $storageKey === '') {
            return null;
        }

        $documentId = $file->getComment()->getDocument()->getId();
        if ($documentId === null) {
            return null;
        }

        $absolutePath = $this->commentsUploadDir . '/' . $documentId . '/' . $storageKey;

        return is_file($absolutePath) ? $absolutePath : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function presentComments(iterable $comments, User $viewer): array
    {
        $rows = [];
        foreach ($comments as $comment) {
            if ($comment instanceof DocumentComment) {
                $rows[] = $this->presentComment($comment, $viewer);
            }
        }

        usort(
            $rows,
            static fn (array $a, array $b) => strcmp((string) ($a['createdAt'] ?? ''), (string) ($b['createdAt'] ?? '')),
        );

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentComment(DocumentComment $comment, User $viewer): array
    {
        $author = $comment->getAuthor();
        $canManage = $this->canManageComment($comment, $viewer);

        $files = [];
        foreach ($comment->getFiles() as $file) {
            $files[] = $this->presentCommentFile($file);
        }

        usort(
            $files,
            static fn (array $a, array $b) => strcmp((string) ($a['createdAt'] ?? ''), (string) ($b['createdAt'] ?? '')),
        );

        return [
            'id' => $comment->getId(),
            'body' => $comment->getBody(),
            'author' => $this->presenter->presentUserBrief($author),
            'authorId' => $author->getId(),
            'createdAt' => $comment->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $comment->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'canEdit' => $canManage,
            'canDelete' => $canManage,
            'files' => $files,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentCommentFile(DocumentCommentFile $file): array
    {
        return [
            'id' => $file->getId(),
            'filename' => $file->getFilename() ?? 'file',
            'contentType' => $file->getContentType(),
            'size' => $file->getSizeBytes(),
            'createdAt' => $file->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    public function canManageComment(DocumentComment $comment, User $user): bool
    {
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        return $comment->getAuthor()->getId() === $user->getId();
    }

    private function assertCanManageComment(DocumentComment $comment, User $user): void
    {
        if (!$this->canManageComment($comment, $user)) {
            throw new AccessDeniedHttpException(SpaApiError::COMMENT_AUTHOR_ONLY);
        }
    }

    private function sendCommentNotifications(Document $document, User $commentAuthor): void
    {
        $recipients = [];

        $creator = $document->getCreatedBy();
        if ($creator instanceof User) {
            $recipients[$creator->getId()] = $creator;
        }

        foreach ($document->getUserRecipients() as $userRecipient) {
            $user = $userRecipient->getUser();
            if ($user instanceof User) {
                $recipients[$user->getId()] = $user;
            }
        }

        unset($recipients[$commentAuthor->getId()]);

        if ($recipients === []) {
            return;
        }

        $authorName = $this->presenter->formatUserFullName($commentAuthor);
        $documentTitle = $document->getName() ?? '';
        $link = '/documents-flow';

        foreach ($recipients as $recipient) {
            $this->notificationService->notifyDocumentCommentAdded(
                $recipient,
                $authorName,
                $documentTitle,
                $link,
            );
        }
    }
}
