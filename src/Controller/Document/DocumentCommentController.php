<?php

namespace App\Controller\Document;

use App\Entity\Document\Document;
use App\Entity\Document\DocumentComment;
use App\Entity\Document\DocumentCommentFile;
use App\Entity\User\User;
use App\Repository\Document\DocumentCommentFileRepository;
use App\Repository\Document\DocumentCommentRepository;
use App\Repository\Document\DocumentRepository;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class DocumentCommentController extends AbstractController
{
    private const MAX_FILE_SIZE = 25 * 1024 * 1024; // 25 МБ

    public function __construct(
        #[Autowire('%private_upload_dir_documents_comments%')]
        private readonly string $commentsUploadDir,
        private readonly NotificationService $notificationService,
    ) {
    }

    #[Route('/document/{id}/comment/create', name: 'app_document_comment_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function create(
        int $id,
        Request $request,
        DocumentRepository $documentRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему.');
            return $this->redirectToRoute('app_login');
        }

        $document = $documentRepository->find($id);
        if (!$document instanceof Document) {
            throw $this->createNotFoundException('Документ не найден.');
        }

        if (!$this->hasAccess($document, $currentUser)) {
            $this->addFlash('error', 'Нет доступа к этому документу.');
            return $this->redirectDocumentView($document, $currentUser);
        }

        if (!$this->isCsrfTokenValid('document_comment_create_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Неверный токен. Попробуйте снова.');
            return $this->redirectDocumentView($document, $currentUser);
        }

        $body = trim((string) $request->request->get('body', ''));
        $uploadedFiles = $request->files->get('files') ?? [];
        if (!is_array($uploadedFiles)) {
            $uploadedFiles = $uploadedFiles ? [$uploadedFiles] : [];
        }
        $uploadedFiles = array_values(array_filter($uploadedFiles, static fn ($f) => $f instanceof UploadedFile));

        if ($body === '' && count($uploadedFiles) === 0) {
            $this->addFlash('error', 'Комментарий не может быть пустым.');
            return $this->redirectDocumentView($document, $currentUser);
        }

        foreach ($uploadedFiles as $uploaded) {
            if ($uploaded->getSize() > self::MAX_FILE_SIZE) {
                $this->addFlash('error', sprintf('Файл «%s» превышает 25 МБ.', $uploaded->getClientOriginalName()));
                return $this->redirectDocumentView($document, $currentUser);
            }
        }

        $comment = new DocumentComment();
        $comment->setDocument($document);
        $comment->setAuthor($currentUser);
        $comment->setBody($body);
        $document->addComment($comment);

        foreach ($uploadedFiles as $uploaded) {
            $attachment = new DocumentCommentFile();
            $attachment->setAuthor($currentUser);
            $attachment->setComment($comment);
            $attachment->setFile($uploaded);
            $comment->addFile($attachment);
            $entityManager->persist($attachment);
        }

        $entityManager->persist($comment);
        $entityManager->flush();

        $this->sendCommentNotifications($document, $currentUser);

        $this->addFlash('success', 'Комментарий добавлен.');
        return $this->redirectDocumentView($document, $currentUser, anchor: 'document-comments');
    }

    #[Route('/document/comment/{id}/edit', name: 'app_document_comment_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function edit(
        int $id,
        Request $request,
        DocumentCommentRepository $commentRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему.');
            return $this->redirectToRoute('app_login');
        }

        $comment = $commentRepository->find($id);
        if (!$comment instanceof DocumentComment) {
            throw $this->createNotFoundException('Комментарий не найден.');
        }

        $document = $comment->getDocument();

        if (!$this->isCsrfTokenValid('document_comment_edit_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Неверный токен. Попробуйте снова.');
            return $this->redirectDocumentView($document, $currentUser);
        }

        $isAuthor = $comment->getAuthor()->getId() === $currentUser->getId();
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        if (!$isAuthor && !$isAdmin) {
            $this->addFlash('error', 'Нельзя редактировать чужие комментарии.');
            return $this->redirectDocumentView($document, $currentUser);
        }

        $body = trim((string) $request->request->get('body', ''));
        if ($body === '' && $comment->getFiles()->count() === 0) {
            $this->addFlash('error', 'Комментарий не может быть пустым.');
            return $this->redirectDocumentView($document, $currentUser, anchor: 'document-comments');
        }

        $comment->setBody($body);
        $entityManager->flush();

        $this->addFlash('success', 'Комментарий изменён.');
        return $this->redirectDocumentView($document, $currentUser, anchor: 'document-comments');
    }

    #[Route('/document/comment/{id}/delete', name: 'app_document_comment_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(
        int $id,
        Request $request,
        DocumentCommentRepository $commentRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему.');
            return $this->redirectToRoute('app_login');
        }

        $comment = $commentRepository->find($id);
        if (!$comment instanceof DocumentComment) {
            throw $this->createNotFoundException('Комментарий не найден.');
        }

        $document = $comment->getDocument();

        if (!$this->isCsrfTokenValid('document_comment_delete_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Неверный токен. Попробуйте снова.');
            return $this->redirectDocumentView($document, $currentUser);
        }

        $isAuthor = $comment->getAuthor()->getId() === $currentUser->getId();
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        if (!$isAuthor && !$isAdmin) {
            $this->addFlash('error', 'Нельзя удалять чужие комментарии.');
            return $this->redirectDocumentView($document, $currentUser);
        }

        $entityManager->remove($comment);
        $entityManager->flush();

        $this->addFlash('success', 'Комментарий удалён.');
        return $this->redirectDocumentView($document, $currentUser, anchor: 'document-comments');
    }

    #[Route('/document/comment/file/{id}/download', name: 'app_document_comment_file_download', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function download(
        int $id,
        Request $request,
        DocumentCommentFileRepository $fileRepository,
    ): Response {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему.');
            return $this->redirectToRoute('app_login');
        }

        $fileEntity = $fileRepository->find($id);
        if (!$fileEntity instanceof DocumentCommentFile) {
            throw $this->createNotFoundException('Файл не найден.');
        }

        $document = $fileEntity->getComment()->getDocument();
        if (!$this->hasAccess($document, $currentUser)) {
            $this->addFlash('error', 'Нет доступа к этому файлу.');
            return $this->redirectToRoute('app_incoming_documents');
        }

        $storageKey = $fileEntity->getStorageKey();
        if (!$storageKey) {
            throw $this->createNotFoundException('Файл не прикреплён.');
        }

        $absolutePath = $this->commentsUploadDir . '/' . $document->getId() . '/' . $storageKey;
        if (!is_file($absolutePath)) {
            throw $this->createNotFoundException('Файл не найден на диске.');
        }

        $downloadName = $fileEntity->getFilename() ?: $storageKey;
        $inline = $request->query->getBoolean('inline');

        $response = new StreamedResponse(static function () use ($absolutePath) {
            $handle = fopen($absolutePath, 'rb');
            if ($handle === false) {
                return;
            }
            while (!feof($handle)) {
                echo fread($handle, 8192);
                flush();
            }
            fclose($handle);
        });

        $response->headers->set('Content-Type', $fileEntity->getContentType() ?: 'application/octet-stream');
        $response->headers->set('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . addslashes($downloadName) . '"');

        return $response;
    }

    private function hasAccess(Document $document, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        if ($document->getCreatedBy() && $document->getCreatedBy()->getId() === $user->getId()) {
            return true;
        }
        foreach ($document->getUserRecipients() as $recipient) {
            if ($recipient->getUser() && $recipient->getUser()->getId() === $user->getId()) {
                return true;
            }
        }
        return false;
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

        $authorName = trim($commentAuthor->getLastname() . ' ' . $commentAuthor->getFirstname()) ?: $commentAuthor->getLogin();
        $documentTitle = $document->getName() ?? '';
        $anchor = '#document-comments';
        $creatorId = $creator?->getId();

        $outgoingLink = $this->generateUrl('app_view_outgoing_document', ['id' => $document->getId()]) . $anchor;
        $incomingLink = $this->generateUrl('app_view_incoming_document', ['id' => $document->getId()]) . $anchor;

        foreach ($recipients as $recipient) {
            $link = ($creatorId !== null && $recipient->getId() === $creatorId) ? $outgoingLink : $incomingLink;
            $this->notificationService->notifyDocumentCommentAdded($recipient, $authorName, $documentTitle, $link);
        }
    }

    private function redirectDocumentView(Document $document, User $user, ?string $anchor = null): Response
    {
        $isCreator = $document->getCreatedBy() && $document->getCreatedBy()->getId() === $user->getId();
        $routeName = $isCreator ? 'app_view_outgoing_document' : 'app_view_incoming_document';
        $url = $this->generateUrl($routeName, ['id' => $document->getId()]);
        if ($anchor) {
            $url .= '#' . $anchor;
        }
        return $this->redirect($url, Response::HTTP_SEE_OTHER);
    }
}
