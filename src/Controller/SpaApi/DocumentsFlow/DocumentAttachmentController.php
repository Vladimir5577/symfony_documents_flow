<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\DocumentsFlow;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/documents-flow/outgoing/{id}/attachments')]
final class DocumentAttachmentController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly FileRepository $fileRepository,
        private readonly DocumentAccessService $accessService,
        private readonly DocumentAttachmentService $attachmentService,
    ) {
    }

    #[Route('', name: 'spa_api_documents_flow_outgoing_attachments_upload', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function upload(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->resolveDocument($id, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        if (!$this->accessService->canEditOutgoingDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $uploadedFile = $request->files->get('file');
        if ($uploadedFile === null) {
            return $this->json(['error' => SpaApiError::FILE_NOT_PROVIDED], Response::HTTP_BAD_REQUEST);
        }

        try {
            $file = $this->attachmentService->upload($document, $uploadedFile);
        } catch (BadRequestHttpException $e) {
            $message = $e->getMessage();

            return $this->json(
                ['error' => $message !== '' ? $message : SpaApiError::DOCUMENT_VALIDATION_FAILED],
                Response::HTTP_BAD_REQUEST,
            );
        }

        return $this->json($this->attachmentService->presentAttachment($file), Response::HTTP_CREATED);
    }

    #[Route('/{attachmentId}/download', name: 'spa_api_documents_flow_outgoing_attachments_download', requirements: ['id' => '\d+', 'attachmentId' => '\d+'], methods: ['GET'])]
    public function download(int $id, int $attachmentId, Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->resolveDocument($id, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        $file = $this->resolveAttachment($document, $attachmentId);
        if ($file instanceof JsonResponse) {
            return $file;
        }

        $absolutePath = $this->attachmentService->resolveAbsolutePath($file);
        if ($absolutePath === null) {
            return $this->json(['error' => SpaApiError::FILE_NOT_FOUND_ON_DISK], Response::HTTP_NOT_FOUND);
        }

        $presented = $this->attachmentService->presentAttachment($file);
        $response = new BinaryFileResponse($absolutePath);
        $disposition = $request->query->getBoolean('inline', false)
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;
        $response->setContentDisposition($disposition, (string) $presented['filename']);

        return $response;
    }

    #[Route('/{attachmentId}', name: 'spa_api_documents_flow_outgoing_attachments_delete', requirements: ['id' => '\d+', 'attachmentId' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, int $attachmentId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->resolveDocument($id, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        if (!$this->accessService->canEditOutgoingDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $file = $this->resolveAttachment($document, $attachmentId);
        if ($file instanceof JsonResponse) {
            return $file;
        }

        $this->attachmentService->delete($file);

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

    private function resolveAttachment(Document $document, int $attachmentId): File|JsonResponse
    {
        $file = $this->fileRepository->find($attachmentId);
        if (!$file instanceof File || $file->getDocument()?->getId() !== $document->getId()) {
            return $this->json(['error' => SpaApiError::ATTACHMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        return $file;
    }
}
