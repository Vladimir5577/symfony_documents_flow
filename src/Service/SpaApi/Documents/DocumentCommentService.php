<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentComment;
use App\Entity\Document\DocumentCommentFile;
use App\Entity\User\User;
use App\Repository\Document\DocumentCommentRepository;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class DocumentCommentService
{
    private const MAX_FILE_SIZE = 25 * 1024 * 1024;

    public function __construct(
        private readonly DocumentCommentRepository $commentRepository,
        private readonly DocumentApiPresenter $presenter,
        private readonly DocumentAccessService $accessService,
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationService $notificationService,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%private_upload_dir_documents_comments%')]
        private readonly string $commentsUploadDir,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function presentCommentsForDocument(int $documentId, User $viewer): array
    {
        return $this->presenter->presentDocumentComments(
            $this->findByDocumentId($documentId),
            $viewer,
            $this->accessService->isAdmin(),
        );
    }

    public function findCommentForDocument(int $documentId, int $commentId): ?DocumentComment
    {
        return $this->createCommentQueryBuilder()
            ->andWhere('c.id = :commentId')
            ->andWhere('d.id = :documentId')
            ->setParameter('commentId', $commentId)
            ->setParameter('documentId', $documentId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<UploadedFile> $uploadedFiles
     */
    public function create(
        Document $document,
        User $author,
        string $body,
        array $uploadedFiles,
    ): DocumentComment {
        if ($body === '' && $uploadedFiles === []) {
            throw new BadRequestHttpException(SpaApiError::COMMENT_VALIDATION_FAILED);
        }

        foreach ($uploadedFiles as $uploaded) {
            if ($uploaded->getSize() > self::MAX_FILE_SIZE) {
                throw new BadRequestHttpException(SpaApiError::POST_FILE_TOO_LARGE);
            }
        }

        $comment = new DocumentComment();
        $comment->setDocument($document);
        $comment->setAuthor($author);
        $comment->setBody($body);
        $document->addComment($comment);
        $this->entityManager->persist($comment);

        foreach ($uploadedFiles as $uploaded) {
            $attachment = new DocumentCommentFile();
            $attachment->setAuthor($author);
            $attachment->setComment($comment);
            $attachment->setFile($uploaded);
            $comment->addFile($attachment);
            $this->entityManager->persist($attachment);
        }

        $this->entityManager->flush();

        $this->sendCommentNotifications($document, $author);

        return $comment;
    }

    public function update(DocumentComment $comment, string $body): void
    {
        if ($body === '' && $comment->getFiles()->count() === 0) {
            throw new BadRequestHttpException(SpaApiError::COMMENT_VALIDATION_FAILED);
        }

        $comment->setBody($body);
        $this->entityManager->flush();
    }

    public function delete(DocumentComment $comment): void
    {
        $this->entityManager->remove($comment);
        $this->entityManager->flush();
    }

    public function resolveCommentFilePath(Document $document, DocumentCommentFile $fileEntity): ?string
    {
        $storageKey = $fileEntity->getStorageKey();
        if ($storageKey === null || $storageKey === '') {
            return null;
        }

        $absolutePath = $this->commentsUploadDir . '/' . $document->getId() . '/' . $storageKey;

        return is_file($absolutePath) ? $absolutePath : null;
    }

    /**
     * @return list<UploadedFile>
     */
    public function extractUploadedFiles(mixed $filesBag): array
    {
        $uploadedFiles = $filesBag ?? [];
        if (!is_array($uploadedFiles)) {
            $uploadedFiles = $uploadedFiles instanceof UploadedFile ? [$uploadedFiles] : [];
        }

        return array_values(array_filter(
            $uploadedFiles,
            static fn ($file): bool => $file instanceof UploadedFile,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function presentComment(DocumentComment $comment, User $viewer): array
    {
        return $this->presenter->presentDocumentComment(
            $comment,
            $viewer,
            $this->accessService->isAdmin(),
        );
    }

    public function requireCommentForDocument(int $documentId, int $commentId): DocumentComment
    {
        $comment = $this->findCommentForDocument($documentId, $commentId);
        if (!$comment instanceof DocumentComment) {
            throw new NotFoundHttpException(SpaApiError::COMMENT_NOT_FOUND);
        }

        return $comment;
    }

    /**
     * @return list<DocumentComment>
     */
    private function findByDocumentId(int $documentId): array
    {
        return $this->createCommentQueryBuilder()
            ->andWhere('d.id = :documentId')
            ->setParameter('documentId', $documentId)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function createCommentQueryBuilder(): \Doctrine\ORM\QueryBuilder
    {
        return $this->commentRepository->createQueryBuilder('c')
            ->innerJoin('c.document', 'd')->addSelect('d')
            ->leftJoin('c.author', 'a')->addSelect('a')
            ->leftJoin('c.files', 'f')->addSelect('f');
    }

    private function sendCommentNotifications(Document $document, User $commentAuthor): void
    {
        $recipients = [];

        $creator = $document->getCreatedBy();
        if ($creator instanceof User) {
            $recipients[$creator->getId()] = $creator;
        }

        foreach ($document->getUserRecipients() as $userRecipient) {
            $recipientUser = $userRecipient->getUser();
            if ($recipientUser instanceof User) {
                $recipients[$recipientUser->getId()] = $recipientUser;
            }
        }

        unset($recipients[$commentAuthor->getId()]);

        if ($recipients === []) {
            return;
        }

        $authorName = trim($commentAuthor->getLastname() . ' ' . $commentAuthor->getFirstname()) ?: $commentAuthor->getLogin();
        $documentTitle = $document->getName() ?? '';
        $anchor = '#document-comments';
        $creatorId = $creator?->getId();

        $outgoingLink = $this->urlGenerator->generate('app_view_outgoing_document', ['id' => $document->getId()]) . $anchor;
        $incomingLink = $this->urlGenerator->generate('app_view_incoming_document', ['id' => $document->getId()]) . $anchor;

        foreach ($recipients as $recipient) {
            $link = ($creatorId !== null && $recipient->getId() === $creatorId) ? $outgoingLink : $incomingLink;
            $this->notificationService->notifyDocumentCommentAdded($recipient, $authorName, $documentTitle, $link);
        }
    }
}
