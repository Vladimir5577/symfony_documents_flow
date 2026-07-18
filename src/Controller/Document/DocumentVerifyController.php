<?php

declare(strict_types=1);

namespace App\Controller\Document;

use App\Entity\Document\Document;
use App\Entity\User\User;
use App\Enum\Document\DocumentStatus;
use App\Repository\Document\DocumentRepository;
use App\Service\Document\Signature\SignatureVerificationService;
use App\Service\Document\Signature\SignedFormGenerator;
use App\Service\SpaApi\Documents\DocumentAccessService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Страницы проверки электронной подписи (Фаза 5, T5.2):
 * /verify — проверка загруженного файла по содержимому (файл не сохраняется);
 * /verify/{code} — страница по QR-коду с печатной формы.
 *
 * Доступ — любой авторизованный пользователь (внутренний портал): страница
 * показывает факт подписания и подписантов, но не содержимое документа.
 * Скачивание печатной формы — только при доступе к самому документу.
 */
final class DocumentVerifyController extends AbstractController
{
    private const MAX_FILE_SIZE = 9 * 1024 * 1024; // 9 МБ, как в проекте

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly SignatureVerificationService $verificationService,
        private readonly DocumentAccessService $accessService,
        private readonly SignedFormGenerator $signedFormGenerator,
        #[Autowire('%private_upload_dir_documents_signed_forms%')]
        private readonly string $signedFormsDir,
    ) {
    }

    #[Route('/verify', name: 'app_verify', methods: ['GET', 'POST'])]
    public function verify(Request $request): Response
    {
        $context = ['active_tab' => 'verify', 'checked' => false];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('verify_file', (string) $request->request->get('_token', ''))) {
                $this->addFlash('error', 'Недействительный CSRF-токен. Повторите попытку.');

                return $this->redirectToRoute('app_verify');
            }

            $error = null;
            $file = $request->files->get('file');
            $binary = null;

            if (!$file instanceof UploadedFile || !$file->isValid()) {
                $error = 'Файл не загружен.';
            } elseif ($file->getSize() > self::MAX_FILE_SIZE) {
                $error = 'Файл слишком большой (лимит 9 МБ).';
            } else {
                $binary = (string) file_get_contents($file->getPathname());
                if (!str_starts_with($binary, '%PDF')) {
                    $error = 'Поддерживается только PDF (канонический формат подписываемых документов).';
                }
            }

            if ($error !== null) {
                $this->addFlash('error', $error);

                return $this->redirectToRoute('app_verify');
            }

            // файл не сохраняется — только хэш содержимого в памяти
            $document = $this->verificationService->findByFileContent((string) $binary);

            $context['checked'] = true;
            $context['document'] = $document;
            $context['result'] = $document !== null ? $this->verificationService->verifyDocument($document) : null;
        }

        return $this->render('verify/verify.html.twig', $context);
    }

    #[Route('/verify/{code}', name: 'app_verify_code', requirements: ['code' => '[0-9a-f]{16}'], methods: ['GET'])]
    public function verifyByCode(string $code, #[CurrentUser] ?User $user): Response
    {
        $document = $this->findByCode($code);

        return $this->render('verify/code.html.twig', [
            'active_tab' => 'verify',
            'document' => $document,
            'result' => $this->verificationService->verifyDocument($document),
            'can_download' => $user instanceof User && $this->accessService->canViewDocument($document, $user),
        ]);
    }

    #[Route('/verify/{code}/signed-form', name: 'app_verify_signed_form', requirements: ['code' => '[0-9a-f]{16}'], methods: ['GET'])]
    public function downloadSignedForm(string $code, #[CurrentUser] ?User $user): Response
    {
        $document = $this->findByCode($code);

        if (!$user instanceof User || !$this->accessService->canViewDocument($document, $user)) {
            throw $this->createAccessDeniedException();
        }

        $fileName = $document->getSignedFormFile();
        $path = $fileName !== null ? $this->signedFormsDir . \DIRECTORY_SEPARATOR . basename($fileName) : null;

        if ($path === null || !is_file($path)) {
            if ($document->getStatus() !== DocumentStatus::SIGNED) {
                throw $this->createNotFoundException('Печатная форма ещё не готова.');
            }

            $fileName = $this->signedFormGenerator->generate($document);
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

    private function findByCode(string $code): Document
    {
        $document = $this->documentRepository->findOneBy(['verificationCode' => $code]);
        if ($document === null) {
            throw $this->createNotFoundException('Документ с таким кодом проверки не найден.');
        }

        return $document;
    }
}
