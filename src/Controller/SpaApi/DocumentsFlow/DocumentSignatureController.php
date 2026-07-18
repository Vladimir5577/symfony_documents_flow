<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\DocumentsFlow;

use App\Controller\SpaApi\SpaApiError;
use App\DTO\Document\Signature\SignatureVerificationResult;
use App\Entity\Document\Document;
use App\Entity\Document\UserCertificate;
use App\Entity\User\User;
use App\Enum\Document\CertificateStatus;
use App\Enum\Document\DocumentStatus;
use App\Repository\Document\DocumentRepository;
use App\Repository\Document\UserCertificateRepository;
use App\Service\Document\Signature\SignatureVerificationService;
use App\Service\Document\Signature\SignedFormGenerator;
use App\Service\Document\Signature\SigningService;
use App\Service\SpaApi\Documents\DocumentAccessService;
use App\Service\SpaApi\Documents\DocumentApiPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * SPA API подписания документов (Фаза 3, T3.1): отправка на подпись,
 * блок подписей, ПЭП, УНЭП (challenge + подпись), отказ.
 * Вся доменная логика — в SigningService/SignatureVerificationService.
 */
#[Route('/spa/api/documents-flow')]
final class DocumentSignatureController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly UserCertificateRepository $certificateRepository,
        private readonly DocumentAccessService $accessService,
        private readonly DocumentApiPresenter $presenter,
        private readonly SigningService $signingService,
        private readonly SignatureVerificationService $verificationService,
        private readonly SignedFormGenerator $signedFormGenerator,
        #[Autowire('%private_upload_dir_documents_signed_forms%')]
        private readonly string $signedFormsDir,
    ) {
    }

    #[Route('/documents/{id}/send-to-signing', name: 'spa_api_documents_flow_send_to_signing', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sendToSigning(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->findDocument($id, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        try {
            $this->signingService->sendToSigning($document, $user);
        } catch (HttpException $e) {
            return $this->jsonError($e);
        }

        return $this->json(['document' => $this->presenter->presentDocumentListItem($document, $user)]);
    }

    #[Route('/documents/{id}/signatures', name: 'spa_api_documents_flow_signatures', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function signatures(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->findDocument($id, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        $result = $this->verificationService->verifyDocument($document);

        return $this->json([
            'signatures' => array_map(
                fn (SignatureVerificationResult $r): array => $this->presentSignatureRow($r),
                $result->signatures,
            ),
            'documentHash' => $document->getCanonicalFileHash(),
            'verificationCode' => $document->getVerificationCode(),
            'allSigned' => $this->presenter->presentDocumentListItem($document, $user)['allSigned'],
        ]);
    }

    #[Route('/documents/{id}/sign/simple', name: 'spa_api_documents_flow_sign_simple', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function signSimple(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->findDocument($id, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        $payload = $this->decodeJsonBody($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        try {
            $this->signingService->signSimple(
                $document,
                $user,
                (string) ($payload['password'] ?? ''),
                $request->getClientIp(),
                $request->headers->get('User-Agent'),
            );
        } catch (HttpException $e) {
            return $this->jsonError($e);
        }

        return $this->json(['document' => $this->presenter->presentDocumentListItem($document, $user)]);
    }

    #[Route('/documents/{id}/sign/challenge', name: 'spa_api_documents_flow_sign_challenge', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function signChallenge(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->findDocument($id, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        if ($document->getStatus() !== DocumentStatus::ON_SIGNING) {
            return $this->json(['error' => SpaApiError::DOCUMENT_NOT_ON_SIGNING], Response::HTTP_BAD_REQUEST);
        }

        $now = new \DateTimeImmutable();
        $certificates = array_values(array_filter(
            $this->certificateRepository->findBy(['user' => $user, 'status' => CertificateStatus::ACTIVE]),
            static fn (UserCertificate $c): bool => $c->getValidFrom() <= $now && $now <= $c->getValidTo(),
        ));

        return $this->json([
            'documentHash' => $document->getCanonicalFileHash(),
            'algorithm' => 'RSA-SHA256',
            'certificates' => array_map(
                static fn (UserCertificate $c): array => [
                    'id' => $c->getId(),
                    'serialNumber' => $c->getSerialNumber(),
                    'validTo' => $c->getValidTo()?->format(\DateTimeInterface::ATOM),
                ],
                $certificates,
            ),
        ]);
    }

    #[Route('/documents/{id}/sign/enhanced', name: 'spa_api_documents_flow_sign_enhanced', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function signEnhanced(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->findDocument($id, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        $payload = $this->decodeJsonBody($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $certificate = $this->certificateRepository->find((int) ($payload['certificateId'] ?? 0));
        if ($certificate === null) {
            return $this->json(['error' => SpaApiError::CERTIFICATE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->signingService->signEnhanced(
                $document,
                $user,
                (string) ($payload['signature'] ?? ''),
                $certificate,
                $request->getClientIp(),
                $request->headers->get('User-Agent'),
            );
        } catch (HttpException $e) {
            return $this->jsonError($e);
        }

        return $this->json(['document' => $this->presenter->presentDocumentListItem($document, $user)]);
    }

    #[Route('/documents/{id}/decline-signing', name: 'spa_api_documents_flow_decline_signing', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function declineSigning(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->findDocument($id, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        $payload = $this->decodeJsonBody($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        try {
            $this->signingService->decline($document, $user, (string) ($payload['reason'] ?? ''));
        } catch (HttpException $e) {
            return $this->jsonError($e);
        }

        return $this->json(['document' => $this->presenter->presentDocumentListItem($document, $user)]);
    }

    // ------------------------------------------------------------ verify-file

    private const VERIFY_FILE_MAX_SIZE = 9 * 1024 * 1024; // 9 МБ, как в проекте

    #[Route('/verify-file', name: 'spa_api_documents_flow_verify_file', methods: ['POST'])]
    public function verifyFile(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->json(['error' => SpaApiError::FILE_NOT_PROVIDED], Response::HTTP_BAD_REQUEST);
        }

        if ($file->getSize() > self::VERIFY_FILE_MAX_SIZE) {
            return $this->json(['error' => SpaApiError::FILE_TOO_LARGE], Response::HTTP_BAD_REQUEST);
        }

        // проверяем только PDF (канонический формат) — по mime и сигнатуре содержимого
        $binary = (string) file_get_contents($file->getPathname());
        if (!str_starts_with($binary, '%PDF')) {
            return $this->json(['error' => SpaApiError::FILE_INVALID_TYPE], Response::HTTP_BAD_REQUEST);
        }

        // файл не сохраняется: только SHA-256 содержимого в памяти
        $document = $this->verificationService->findByFileContent($binary);
        if ($document === null) {
            return $this->json(['found' => false]);
        }

        $result = $this->verificationService->verifyDocument($document);

        return $this->json([
            'found' => true,
            'document' => [
                'id' => $document->getId(),
                'name' => $document->getName(),
                'status' => $document->getStatus()?->value,
            ],
            'signatures' => array_map(
                fn (SignatureVerificationResult $r): array => $this->presentSignatureRow($r),
                $result->signatures,
            ),
        ]);
    }

    // ------------------------------------------------------------ signed-form

    #[Route('/documents/{id}/signed-form', name: 'spa_api_documents_flow_signed_form', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function signedForm(int $id, #[CurrentUser] ?User $user): Response
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->findDocument($id, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        $fileName = $document->getSignedFormFile();
        $path = $fileName !== null ? $this->signedFormsDir . \DIRECTORY_SEPARATOR . basename($fileName) : null;

        if ($path === null || !is_file($path)) {
            // ленивую генерацию допускаем только для подписанного документа
            if ($document->getStatus() !== DocumentStatus::SIGNED) {
                return $this->json(['error' => SpaApiError::SIGNED_FORM_NOT_READY], Response::HTTP_BAD_REQUEST);
            }

            try {
                $fileName = $this->signedFormGenerator->generate($document);
            } catch (\Throwable) {
                return $this->json(['error' => SpaApiError::SIGNED_FORM_NOT_READY], Response::HTTP_BAD_REQUEST);
            }
            $path = $this->signedFormsDir . \DIRECTORY_SEPARATOR . $fileName;
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('signed_form_%d.pdf', $document->getId() ?? 0),
        );

        return $response;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function presentSignatureRow(SignatureVerificationResult $result): array
    {
        $signature = $result->signature;

        $details = $result->details;
        if ($result->reason !== null) {
            $details = ['reason' => $result->reason] + $details;
        }

        return [
            'signer' => $this->presenter->presentUserBrief($signature->getSigner()),
            'level' => $signature->getLevel()?->value,
            'signedAt' => $signature->getSignedAt()?->format(\DateTimeInterface::ATOM),
            'certificateSerial' => $signature->getCertificate()?->getSerialNumber(),
            'valid' => $result->valid,
            'validityDetails' => $details === [] ? null : $details,
        ];
    }

    private function findDocument(int $id, User $user): Document|JsonResponse
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

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function decodeJsonBody(Request $request): array|JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        return $payload;
    }

    private function jsonError(HttpException $e): JsonResponse
    {
        $message = $e->getMessage();

        return $this->json(
            ['error' => $message !== '' ? $message : SpaApiError::DOCUMENT_VALIDATION_FAILED],
            $e->getStatusCode(),
        );
    }
}
