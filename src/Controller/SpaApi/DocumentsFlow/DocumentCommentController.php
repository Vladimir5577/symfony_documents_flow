<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\DocumentsFlow;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentComment;
use App\Entity\Document\DocumentCommentFile;
use App\Entity\User\User;
use App\Repository\Document\DocumentCommentFileRepository;
use App\Repository\Document\DocumentCommentRepository;
use App\Repository\Document\DocumentRepository;
use App\Service\SpaApi\Documents\DocumentAccessService;
use App\Service\SpaApi\Documents\DocumentCommentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/documents-flow/documents/{id}/comments')]
final class DocumentCommentController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentCommentRepository $commentRepository,
        private readonly DocumentCommentFileRepository $commentFileRepository,
        private readonly DocumentAccessService $accessService,
        private readonly DocumentCommentService $commentService,
    ) {
    }

    #[Route('', name: 'spa_api_documents_flow_comments_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function create(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->resolveDocument($id, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        if (!$this->accessService->presentPermissions($document, $user)['canComment']) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $body = trim((string) $request->request->get('body', ''));

        try {
            $comment = $this->commentService->create(
                $document,
                $user,
                $body,
                $this->extractUploadedFiles($request),
            );
        } catch (BadRequestHttpException $e) {
            $message = $e->getMessage();

            return $this->json(
                ['error' => $message !== '' ? $message : SpaApiError::COMMENT_BODY_REQUIRED],
                Response::HTTP_BAD_REQUEST,
            );
        }

        return $this->json($this->commentService->presentComment($comment, $user), Response::HTTP_CREATED);
    }

    #[Route('/files/{fileId}/download', name: 'spa_api_documents_flow_comment_file_download', requirements: ['id' => '\d+', 'fileId' => '\d+'], methods: ['GET'])]
    public function downloadFile(int $id, int $fileId, Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->resolveDocument($id, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        $file = $this->commentFileRepository->find($fileId);
        if (
            !$file instanceof DocumentCommentFile
            || $file->getComment()->getDocument()->getId() !== $document->getId()
        ) {
            return $this->json(['error' => SpaApiError::ATTACHMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $absolutePath = $this->commentService->resolveAbsolutePath($file);
        if ($absolutePath === null) {
            return $this->json(['error' => SpaApiError::FILE_NOT_FOUND_ON_DISK], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($absolutePath);
        $disposition = $request->query->getBoolean('inline', false)
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;
        $response->setContentDisposition($disposition, $file->getFilename() ?? 'file');

        return $response;
    }

    #[Route('/{commentId}', name: 'spa_api_documents_flow_comments_update', requirements: ['id' => '\d+', 'commentId' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, int $commentId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->resolveDocument($id, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        $comment = $this->resolveComment($document, $commentId);
        if ($comment instanceof JsonResponse) {
            return $comment;
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        try {
            $comment = $this->commentService->update(
                $comment,
                $user,
                (string) ($payload['body'] ?? ''),
            );
        } catch (AccessDeniedHttpException) {
            return $this->json(['error' => SpaApiError::COMMENT_AUTHOR_ONLY], Response::HTTP_FORBIDDEN);
        } catch (BadRequestHttpException $e) {
            $message = $e->getMessage();

            return $this->json(
                ['error' => $message !== '' ? $message : SpaApiError::COMMENT_BODY_REQUIRED],
                Response::HTTP_BAD_REQUEST,
            );
        }

        return $this->json($this->commentService->presentComment($comment, $user));
    }

    #[Route('/{commentId}', name: 'spa_api_documents_flow_comments_delete', requirements: ['id' => '\d+', 'commentId' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, int $commentId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->resolveDocument($id, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        $comment = $this->resolveComment($document, $commentId);
        if ($comment instanceof JsonResponse) {
            return $comment;
        }

        try {
            $this->commentService->delete($comment, $user);
        } catch (AccessDeniedHttpException) {
            return $this->json(['error' => SpaApiError::COMMENT_AUTHOR_ONLY], Response::HTTP_FORBIDDEN);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function resolveDocument(int $id, User $user): Document|JsonResponse
    {
        $document = $this->documentRepository->findOneWithRelations($id);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessService->canViewDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        return $document;
    }

    private function resolveComment(Document $document, int $commentId): DocumentComment|JsonResponse
    {
        $comment = $this->commentRepository->find($commentId);
        if (!$comment instanceof DocumentComment || $comment->getDocument()->getId() !== $document->getId()) {
            return $this->json(['error' => SpaApiError::COMMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        return $comment;
    }

    /**
     * @return list<UploadedFile>
     */
    private function extractUploadedFiles(Request $request): array
    {
        $files = $request->files->all('files');
        if ($files !== []) {
            return array_values(array_filter($files, static fn ($file) => $file instanceof UploadedFile));
        }

        $single = $request->files->get('files');
        if ($single instanceof UploadedFile) {
            return [$single];
        }

        if (is_array($single)) {
            return array_values(array_filter($single, static fn ($file) => $file instanceof UploadedFile));
        }

        return [];
    }
}
