<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\DocumentsFlow;

use App\Controller\SpaApi\SpaApiError;
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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/documents-flow/documents/{documentId}/comments')]
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

    #[Route('', name: 'spa_api_documents_flow_comments_create', requirements: ['documentId' => '\d+'], methods: ['POST'])]
    public function create(int $documentId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->documentRepository->findOneWithRelations($documentId);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessService->canViewDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $body = trim((string) $request->request->get('body', ''));
        $uploadedFiles = $this->normalizeUploadedFiles($request->files->get('files'));

        try {
            $comment = $this->commentService->create($document, $user, $body, $uploadedFiles);
        } catch (HttpException $e) {
            $message = $e->getMessage();

            return $this->json(
                ['error' => $message !== '' ? $message : SpaApiError::COMMENT_VALIDATION_FAILED],
                $e->getStatusCode(),
            );
        }

        return $this->json(
            $this->commentService->presentComment($comment, $user),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{id}', name: 'spa_api_documents_flow_comments_update', requirements: ['documentId' => '\d+', 'id' => '\d+'], methods: ['PATCH'])]
    public function update(int $documentId, int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $comment = $this->findDocumentComment($documentId, $id, $user);
        if ($comment instanceof JsonResponse) {
            return $comment;
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $body = isset($payload['body']) ? trim((string) $payload['body']) : '';

        try {
            $comment = $this->commentService->update($comment, $user, $body);
        } catch (HttpException $e) {
            $message = $e->getMessage();

            return $this->json(
                ['error' => $message !== '' ? $message : SpaApiError::COMMENT_VALIDATION_FAILED],
                $e->getStatusCode(),
            );
        }

        return $this->json($this->commentService->presentComment($comment, $user));
    }

    #[Route('/{id}', name: 'spa_api_documents_flow_comments_delete', requirements: ['documentId' => '\d+', 'id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $documentId, int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $comment = $this->findDocumentComment($documentId, $id, $user);
        if ($comment instanceof JsonResponse) {
            return $comment;
        }

        try {
            $this->commentService->delete($comment, $user);
        } catch (HttpException $e) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], $e->getStatusCode());
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/files/{fileId}/download', name: 'spa_api_documents_flow_comment_files_download', requirements: ['documentId' => '\d+', 'fileId' => '\d+'], methods: ['GET'])]
    public function downloadFile(int $documentId, int $fileId, Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->documentRepository->find($documentId);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessService->canViewDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $file = $this->commentFileRepository->find($fileId);
        if (
            !$file instanceof DocumentCommentFile
            || $file->getComment()->getDocument()->getId() !== $documentId
        ) {
            return $this->json(['error' => SpaApiError::COMMENT_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $absolutePath = $this->commentService->resolveAbsolutePath($file);
        if ($absolutePath === null) {
            return $this->json(['error' => SpaApiError::FILE_NOT_FOUND_ON_DISK], Response::HTTP_NOT_FOUND);
        }

        $presented = $this->commentService->presentCommentFile($file);
        $response = new BinaryFileResponse($absolutePath);
        $disposition = $request->query->getBoolean('inline', false)
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;
        $response->setContentDisposition($disposition, $presented['filename']);

        return $response;
    }

    private function findDocumentComment(int $documentId, int $id, User $user): DocumentComment|JsonResponse
    {
        $document = $this->documentRepository->find($documentId);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessService->canViewDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $comment = $this->commentRepository->find($id);
        if (!$comment instanceof DocumentComment || $comment->getDocument()->getId() !== $documentId) {
            return $this->json(['error' => SpaApiError::COMMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        return $comment;
    }

    /**
     * @return list<\Symfony\Component\HttpFoundation\File\UploadedFile>
     */
    private function normalizeUploadedFiles(mixed $files): array
    {
        if ($files === null) {
            return [];
        }

        if (!is_array($files)) {
            return $files instanceof \Symfony\Component\HttpFoundation\File\UploadedFile ? [$files] : [];
        }

        return array_values(array_filter(
            $files,
            static fn ($file) => $file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile,
        ));
    }
}
