<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\DocumentsFlow;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\File;
use App\Entity\User\User;
use App\Repository\Document\DocumentRepository;
use App\Repository\Document\FileRepository;
use App\Service\SpaApi\Documents\DocumentAccessService;
use App\Service\SpaApi\Documents\DocumentAttachmentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/documents-flow/outgoing/{documentId}/attachments')]
final class DocumentAttachmentController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly FileRepository $fileRepository,
        private readonly DocumentAccessService $accessService,
        private readonly DocumentAttachmentService $attachmentService,
    ) {
    }

    #[Route('', name: 'spa_api_documents_flow_outgoing_attachments_upload', requirements: ['documentId' => '\d+'], methods: ['POST'])]
    public function upload(int $documentId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->documentRepository->findOneWithRelations($documentId);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessService->canEditOutgoingDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        if ($document->getFiles()->count() >= DocumentAttachmentService::MAX_ATTACHMENTS_PER_DOCUMENT) {
            return $this->json(['error' => SpaApiError::ATTACHMENT_LIMIT_REACHED], Response::HTTP_BAD_REQUEST);
        }

        $file = $request->files->get('file');
        if ($file === null) {
            return $this->json(['error' => SpaApiError::FILE_NOT_PROVIDED], Response::HTTP_BAD_REQUEST);
        }

        try {
            $attachment = $this->attachmentService->upload($document, $file);
        } catch (HttpException $e) {
            $message = $e->getMessage();

            return $this->json(
                ['error' => $message !== '' ? $message : SpaApiError::DOCUMENT_VALIDATION_FAILED],
                $e->getStatusCode(),
            );
        }

        return $this->json(
            $this->attachmentService->presentAttachment($attachment),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{id}/download', name: 'spa_api_documents_flow_outgoing_attachments_download', requirements: ['documentId' => '\d+', 'id' => '\d+'], methods: ['GET'])]
    public function download(int $documentId, int $id, Request $request, #[CurrentUser] ?User $user): Response
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

        $attachment = $this->findDocumentAttachment($documentId, $id);
        if ($attachment instanceof JsonResponse) {
            return $attachment;
        }

        $absolutePath = $this->attachmentService->resolveAbsolutePath($attachment);
        if ($absolutePath === null) {
            return $this->json(['error' => SpaApiError::FILE_NOT_FOUND_ON_DISK], Response::HTTP_NOT_FOUND);
        }

        $presented = $this->attachmentService->presentAttachment($attachment);
        $response = new BinaryFileResponse($absolutePath);
        $disposition = $request->query->getBoolean('inline', false)
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;
        $response->setContentDisposition($disposition, $presented['filename']);

        return $response;
    }

    #[Route('/{id}', name: 'spa_api_documents_flow_outgoing_attachments_delete', requirements: ['documentId' => '\d+', 'id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $documentId, int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->documentRepository->find($documentId);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessService->canEditOutgoingDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $attachment = $this->findDocumentAttachment($documentId, $id);
        if ($attachment instanceof JsonResponse) {
            return $attachment;
        }

        $this->attachmentService->delete($attachment);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function findDocumentAttachment(int $documentId, int $id): File|JsonResponse
    {
        $attachment = $this->fileRepository->find($id);
        if (!$attachment instanceof File || $attachment->getDocument()?->getId() !== $documentId) {
            return $this->json(['error' => SpaApiError::ATTACHMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        return $attachment;
    }
}
