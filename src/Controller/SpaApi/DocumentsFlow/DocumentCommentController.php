<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\DocumentsFlow;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentCommentFile;
use App\Entity\User\User;
use App\Repository\Document\DocumentCommentFileRepository;
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

        $document = $this->findAccessibleDocument($documentId, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        if (!$this->accessService->canCommentDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        try {
            $comment = $this->commentService->create(
                $document,
                $user,
                trim((string) $request->request->get('body', '')),
                $this->commentService->extractUploadedFiles($request->files->get('files')),
            );
        } catch (HttpException $e) {
            return $this->jsonError($e);
        }

        return $this->json(
            $this->commentService->presentComment($comment, $user),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/files/{fileId}/download', name: 'spa_api_documents_flow_comments_file_download', requirements: ['documentId' => '\d+', 'fileId' => '\d+'], methods: ['GET'])]
    public function downloadFile(int $documentId, int $fileId, Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->findAccessibleDocument($documentId, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        $fileEntity = $this->commentFileRepository->find($fileId);
        if (!$fileEntity instanceof DocumentCommentFile) {
            return $this->json(['error' => SpaApiError::COMMENT_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if ($fileEntity->getComment()->getDocument()->getId() !== $documentId) {
            return $this->json(['error' => SpaApiError::COMMENT_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $absolutePath = $this->commentService->resolveCommentFilePath($document, $fileEntity);
        if ($absolutePath === null) {
            return $this->json(['error' => SpaApiError::FILE_NOT_FOUND_ON_DISK], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($absolutePath);
        $disposition = $request->query->getBoolean('inline')
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;
        $response->setContentDisposition($disposition, $fileEntity->getFilename() ?? 'file');

        return $response;
    }

    #[Route('/{commentId}', name: 'spa_api_documents_flow_comments_update', requirements: ['documentId' => '\d+', 'commentId' => '\d+'], methods: ['PATCH'])]
    public function update(int $documentId, int $commentId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->findAccessibleDocument($documentId, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        try {
            $comment = $this->commentService->requireCommentForDocument($documentId, $commentId);
        } catch (HttpException $e) {
            return $this->jsonError($e);
        }

        if (!$this->accessService->canManageComment($comment, $user)) {
            return $this->json(['error' => SpaApiError::COMMENT_AUTHOR_ONLY], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commentService->update($comment, trim((string) ($payload['body'] ?? '')));
        } catch (HttpException $e) {
            return $this->jsonError($e);
        }

        return $this->json($this->commentService->presentComment($comment, $user));
    }

    #[Route('/{commentId}', name: 'spa_api_documents_flow_comments_delete', requirements: ['documentId' => '\d+', 'commentId' => '\d+'], methods: ['DELETE'])]
    public function delete(int $documentId, int $commentId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->findAccessibleDocument($documentId, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        try {
            $comment = $this->commentService->requireCommentForDocument($documentId, $commentId);
        } catch (HttpException $e) {
            return $this->jsonError($e);
        }

        if (!$this->accessService->canManageComment($comment, $user)) {
            return $this->json(['error' => SpaApiError::COMMENT_AUTHOR_ONLY], Response::HTTP_FORBIDDEN);
        }

        $this->commentService->delete($comment);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function findAccessibleDocument(int $documentId, User $user): Document|JsonResponse
    {
        $document = $this->documentRepository->findOneWithRelations($documentId);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessService->canViewDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        return $document;
    }

    private function jsonError(HttpException $e): JsonResponse
    {
        $message = $e->getMessage();

        return $this->json(
            ['error' => $message !== '' ? $message : SpaApiError::COMMENT_VALIDATION_FAILED],
            $e->getStatusCode(),
        );
    }
}
