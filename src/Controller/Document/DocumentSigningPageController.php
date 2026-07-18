<?php

declare(strict_types=1);

namespace App\Controller\Document;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\UserCertificate;
use App\Entity\User\User;
use App\Enum\Document\CertificateStatus;
use App\Enum\Document\DocumentRecipientRole;
use App\Enum\Document\SignatureLevel;
use App\Repository\Document\DocumentRepository;
use App\Repository\Document\UserCertificateRepository;
use App\Service\Document\Signature\SigningService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Легаси Twig-интерфейс подписания (Фаза 4, T4.1): сессионная аутентификация + CSRF.
 * Серверно вызывает те же сервисы, что и SPA API (SigningService);
 * НЕ ходит в /spa/api/* — у Twig-страниц нет JWT.
 */
final class DocumentSigningPageController extends AbstractController
{
    /** Карта кодов ошибок SigningService → человекочитаемые сообщения. */
    private const ERROR_MESSAGES = [
        SpaApiError::DOCUMENT_SIGNING_FORBIDDEN => 'Отправить документ на подпись может только автор.',
        SpaApiError::DOCUMENT_NOT_APPROVED => 'Документ можно отправить на подпись только из статуса «Утвержден».',
        SpaApiError::DOCUMENT_NO_SIGNERS => 'У документа не назначены подписанты.',
        SpaApiError::DOCUMENT_NOT_ON_SIGNING => 'Документ не находится на подписании.',
        SpaApiError::DOCUMENT_SIGNING_NOT_SIGNER => 'Вы не являетесь подписантом этого документа.',
        SpaApiError::DOCUMENT_SIGNING_WRONG_TURN => 'Сейчас не ваша очередь подписывать документ.',
        SpaApiError::DOCUMENT_SIGNING_ALREADY_SIGNED => 'Вы уже подписали этот документ.',
        SpaApiError::DOCUMENT_SIGNING_LEVEL_INSUFFICIENT => 'Для этого документа требуется усиленная подпись (файл ключа .p12).',
        SpaApiError::INVALID_PASSWORD => 'Неверный пароль.',
        SpaApiError::CERTIFICATE_NOT_FOUND => 'Сертификат не найден или принадлежит другому пользователю.',
        SpaApiError::CERTIFICATE_REVOKED => 'Сертификат отозван. Обратитесь к администратору за новым.',
        SpaApiError::CERTIFICATE_EXPIRED => 'Срок действия сертификата истёк.',
        SpaApiError::INVALID_SIGNATURE => 'Подпись не прошла проверку. Убедитесь, что выбран правильный файл ключа.',
        SpaApiError::REASON_REQUIRED => 'Укажите причину отказа.',
    ];

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly UserCertificateRepository $certificateRepository,
        private readonly SigningService $signingService,
    ) {
    }

    #[Route('/document/{id}/sign', name: 'app_document_signing_page', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function page(int $id): Response
    {
        $user = $this->requireUser();
        $document = $this->findDocument($id);

        if (!$this->isSigner($document, $user)) {
            throw $this->createAccessDeniedException('Вы не являетесь подписантом этого документа.');
        }

        $now = new \DateTimeImmutable();
        $certificates = array_values(array_filter(
            $this->certificateRepository->findBy(['user' => $user, 'status' => CertificateStatus::ACTIVE]),
            static fn (UserCertificate $c): bool => $c->getValidFrom() <= $now && $now <= $c->getValidTo(),
        ));

        return $this->render('document/signing_page.html.twig', [
            'active_tab' => 'incoming_documents',
            'document' => $document,
            'canSign' => $this->signingService->canSignNow($document, $user),
            'hasSigned' => $this->hasSigned($document, $user),
            'allowSimple' => $document->getSignatureLevel() !== SignatureLevel::ENHANCED,
            'certificates' => array_map(
                static fn (UserCertificate $c): array => [
                    'id' => $c->getId(),
                    'serialNumber' => $c->getSerialNumber(),
                    'validTo' => $c->getValidTo()?->format('d.m.Y'),
                ],
                $certificates,
            ),
        ]);
    }

    #[Route('/document/{id}/sign/simple', name: 'app_document_sign_simple', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function signSimple(int $id, Request $request): Response
    {
        $user = $this->requireUser();
        $document = $this->findDocument($id);

        if (!$this->isCsrfTokenValid('sign_simple_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Неверный CSRF токен.');

            return $this->redirectToRoute('app_document_signing_page', ['id' => $id]);
        }

        try {
            $this->signingService->signSimple(
                $document,
                $user,
                (string) $request->request->get('password', ''),
                $request->getClientIp(),
                $request->headers->get('User-Agent'),
            );
        } catch (HttpException $e) {
            $this->addFlash('error', $this->humanizeError($e->getMessage()));

            return $this->redirectToRoute('app_document_signing_page', ['id' => $id]);
        }

        $this->addFlash('success', 'Документ подписан простой электронной подписью.');

        return $this->redirectToRoute('app_view_incoming_document', ['id' => $id]);
    }

    #[Route('/document/{id}/sign/enhanced', name: 'app_document_sign_enhanced', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function signEnhanced(int $id, Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $document = $this->findDocument($id);

        if (!$this->isCsrfTokenValid('sign_enhanced_' . $id, (string) $request->request->get('_token'))) {
            return $this->json(['error' => 'invalid_csrf', 'message' => 'Неверный CSRF токен. Обновите страницу.'], Response::HTTP_BAD_REQUEST);
        }

        $certificate = $this->certificateRepository->find($request->request->getInt('certificateId'));
        if ($certificate === null) {
            return $this->json([
                'error' => SpaApiError::CERTIFICATE_NOT_FOUND,
                'message' => self::ERROR_MESSAGES[SpaApiError::CERTIFICATE_NOT_FOUND],
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            // чужой сертификат отклоняет сам SigningService (AccessDenied → certificate_not_found)
            $this->signingService->signEnhanced(
                $document,
                $user,
                (string) $request->request->get('signature', ''),
                $certificate,
                $request->getClientIp(),
                $request->headers->get('User-Agent'),
            );
        } catch (HttpException $e) {
            return $this->json([
                'error' => $e->getMessage(),
                'message' => $this->humanizeError($e->getMessage()),
            ], $e->getStatusCode());
        }

        $this->addFlash('success', 'Документ подписан усиленной электронной подписью.');

        return $this->json([
            'success' => true,
            'redirect' => $this->generateUrl('app_view_incoming_document', ['id' => $id]),
        ]);
    }

    #[Route('/document/{id}/send-to-signing', name: 'app_document_send_to_signing', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sendToSigning(int $id, Request $request): Response
    {
        $user = $this->requireUser();
        $document = $this->findDocument($id);

        if (!$this->isCsrfTokenValid('send_to_signing_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Неверный CSRF токен.');

            return $this->redirectToRoute('app_view_outgoing_document', ['id' => $id]);
        }

        try {
            $this->signingService->sendToSigning($document, $user);
            $this->addFlash('success', 'Документ отправлен на подпись.');
        } catch (HttpException $e) {
            $this->addFlash('error', $this->humanizeError($e->getMessage()));
        } catch (\RuntimeException|\LogicException $e) {
            // ошибки заморозки файла (нет исходного файла, неподдерживаемый формат и т.п.)
            $this->addFlash('error', 'Не удалось отправить документ на подпись: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_view_outgoing_document', ['id' => $id]);
    }

    #[Route('/document/{id}/decline-signing', name: 'app_document_decline_signing', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function declineSigning(int $id, Request $request): Response
    {
        $user = $this->requireUser();
        $document = $this->findDocument($id);

        if (!$this->isCsrfTokenValid('decline_signing_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Неверный CSRF токен.');

            return $this->redirectToRoute('app_view_incoming_document', ['id' => $id]);
        }

        try {
            $this->signingService->decline($document, $user, (string) $request->request->get('reason', ''));
            $this->addFlash('success', 'Вы отказались подписывать документ.');
        } catch (HttpException $e) {
            $this->addFlash('error', $this->humanizeError($e->getMessage()));
        }

        return $this->redirectToRoute('app_view_incoming_document', ['id' => $id]);
    }

    // ---------------------------------------------------------------- helpers

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function findDocument(int $id): Document
    {
        $document = $this->documentRepository->findOneWithRelations($id);
        if ($document === null) {
            throw $this->createNotFoundException('Документ не найден.');
        }

        return $document;
    }

    private function isSigner(Document $document, User $user): bool
    {
        foreach ($document->getUserRecipients() as $recipient) {
            if ($recipient->getRole() === DocumentRecipientRole::SIGNER
                && $recipient->getUser()?->getId() === $user->getId()
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasSigned(Document $document, User $user): bool
    {
        foreach ($document->getSignatures() as $signature) {
            if ($signature->getSigner()?->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }

    private function humanizeError(string $code): string
    {
        return self::ERROR_MESSAGES[$code] ?? sprintf('Не удалось выполнить операцию (%s).', $code !== '' ? $code : 'unknown');
    }
}
