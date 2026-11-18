<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\DocumentsFlow;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\File;
use App\Entity\User\User;
use App\Repository\Document\DocumentRepository;
use App\Service\SpaApi\Documents\DocumentAccessService;
use App\Service\SpaApi\Documents\DocumentAttachmentService;
use App\Service\SpaApi\Documents\DocumentFileSignatureService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Подпись PDF-вложения штампом. Подписывает любой, кто видит документ;
 * у каждого один штамп на файл, повторная подпись его переносит.
 */
#[Route('/spa/api/documents-flow/outgoing/{documentId}/attachments/{id}', requirements: ['documentId' => '\d+', 'id' => '\d+'])]
final class DocumentFileSignatureController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentAccessService $accessService,
        private readonly DocumentAttachmentService $attachmentService,
        private readonly DocumentFileSignatureService $signatureService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/signatures', name: 'spa_api_documents_flow_attachment_signatures', methods: ['GET'])]
    public function list(int $documentId, int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $file = $this->findPdf($documentId, $id, $user);
        if ($file instanceof JsonResponse) {
            return $file;
        }

        return $this->json($this->signatureService->present($file, $user));
    }

    #[Route('/signatures', name: 'spa_api_documents_flow_attachment_sign', methods: ['POST'])]
    public function sign(int $documentId, int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $file = $this->findPdf($documentId, $id, $user);
        if ($file instanceof JsonResponse) {
            return $file;
        }

        $data = json_decode($request->getContent(), true);
        $page = $data['page'] ?? null;
        $x = $data['x'] ?? null;
        $y = $data['y'] ?? null;
        if (!\is_int($page) || $page < 1 || !self::isFraction($x) || !self::isFraction($y)) {
            return $this->json(['error' => SpaApiError::DOCUMENT_SIGNATURE_INVALID], Response::HTTP_BAD_REQUEST);
        }

        $this->signatureService->sign($file, $user, $page, (float) $x, (float) $y);

        return $this->json($this->signatureService->present($file, $user));
    }

    #[Route('/signatures', name: 'spa_api_documents_flow_attachment_unsign', methods: ['DELETE'])]
    public function unsign(int $documentId, int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $file = $this->findPdf($documentId, $id, $user);
        if ($file instanceof JsonResponse) {
            return $file;
        }

        $this->signatureService->unsign($file, $user);

        return $this->json($this->signatureService->present($file, $user));
    }

    #[Route('/signed', name: 'spa_api_documents_flow_attachment_signed', methods: ['GET'])]
    public function signed(int $documentId, int $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        $file = $this->findPdf($documentId, $id, $user);
        if ($file instanceof JsonResponse) {
            return $file;
        }

        try {
            $bytes = $this->signatureService->buildSignedPdf($file);
        } catch (\Throwable $e) {
            $this->logger->error('Не удалось собрать PDF с подписями', ['fileId' => $id, 'exception' => $e]);

            return $this->json(['error' => SpaApiError::DOCUMENT_FILE_SIGN_FAILED], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($bytes === null) {
            return $this->json(['error' => SpaApiError::FILE_NOT_FOUND_ON_DISK], Response::HTTP_NOT_FOUND);
        }

        $name = $this->attachmentService->displayName($file);

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $request->query->getBoolean('inline') ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $name,
                preg_replace('/[^\x20-\x7e]|%/', '_', $name) ?? 'file.pdf',
            ),
        ]);
    }

    private function findPdf(int $documentId, int $id, ?User $user): File|JsonResponse
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

        $file = $this->attachmentService->findForDocument($documentId, $id);
        if ($file === null) {
            return $this->json(['error' => SpaApiError::ATTACHMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        if (!DocumentFileSignatureService::isPdf($file)) {
            return $this->json(['error' => SpaApiError::DOCUMENT_FILE_NOT_PDF], Response::HTTP_BAD_REQUEST);
        }

        return $file;
    }

    private static function isFraction(mixed $value): bool
    {
        return (\is_int($value) || \is_float($value)) && $value >= 0 && $value <= 1;
    }
}
