<?php

declare(strict_types=1);

namespace App\Service\Document\Signature;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentHistory;
use App\Entity\Document\DocumentSignature;
use App\Entity\Document\DocumentUserRecipient;
use App\Entity\Document\UserCertificate;
use App\Entity\User\User;
use App\Enum\Document\CertificateStatus;
use App\Enum\Document\DocumentRecipientRole;
use App\Enum\Document\DocumentStatus;
use App\Enum\Document\SignatureLevel;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Оркестрация процесса подписания: отправка на подпись, ПЭП, УНЭП, отказ.
 *
 * Очередь: право подписи имеют подписанты с минимальным signingOrder среди
 * ещё не подписавших (при равных order — все параллельно).
 * Ошибки — HTTP-исключения с кодами SpaApiError (как в остальных SPA-сервисах).
 */
#[WithMonologChannel('signature')]
final class SigningService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentFreezeService $freezeService,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly NotificationService $notificationService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly SignedFormGenerator $signedFormGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendToSigning(Document $document, User $actor): void
    {
        if (!$this->isSameUser($document->getCreatedBy(), $actor)) {
            throw new AccessDeniedHttpException(SpaApiError::DOCUMENT_SIGNING_FORBIDDEN);
        }

        if ($document->getStatus() !== DocumentStatus::APPROVED) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_NOT_APPROVED);
        }

        if ($this->getSigners($document) === []) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_NO_SIGNERS);
        }

        $this->freezeService->freeze($document);

        $document->setSentToSigningAt(new \DateTimeImmutable());
        $document->setStatus(DocumentStatus::ON_SIGNING);
        $this->addHistory($document, $actor, 'sent_to_signing', DocumentStatus::APPROVED, DocumentStatus::ON_SIGNING);
        $this->entityManager->flush();

        // всем при параллельном режиме, первому(-ым) по очереди — при последовательном
        $this->notifySigners(
            $document,
            $this->getCurrentTurnSigners($document),
            sprintf('Вам на подпись поступил документ «%s».', (string) $document->getName()),
        );
    }

    public function signSimple(
        Document $document,
        User $signer,
        string $password,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): DocumentSignature {
        $recipient = $this->assertCanSign($document, $signer);

        if ($document->getSignatureLevel() === SignatureLevel::ENHANCED) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_SIGNING_LEVEL_INSUFFICIENT);
        }

        if (!$this->passwordHasher->isPasswordValid($signer, $password)) {
            throw new BadRequestHttpException(SpaApiError::INVALID_PASSWORD);
        }

        $signature = $this->makeSignature($document, $signer, $ipAddress, $userAgent)
            ->setLevel(SignatureLevel::SIMPLE)
            ->setAlgorithm('password-confirmation');

        $this->recordSignature($document, $recipient, $signature);

        return $signature;
    }

    public function signEnhanced(
        Document $document,
        User $signer,
        string $signatureB64,
        UserCertificate $certificate,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): DocumentSignature {
        $recipient = $this->assertCanSign($document, $signer);

        if (!$this->isSameUser($certificate->getUser(), $signer)) {
            throw new AccessDeniedHttpException(SpaApiError::CERTIFICATE_NOT_FOUND);
        }

        if ($certificate->getStatus() === CertificateStatus::REVOKED) {
            throw new BadRequestHttpException(SpaApiError::CERTIFICATE_REVOKED);
        }

        $now = new \DateTimeImmutable();
        if ($now < $certificate->getValidFrom() || $now > $certificate->getValidTo()) {
            throw new BadRequestHttpException(SpaApiError::CERTIFICATE_EXPIRED);
        }

        $publicKey = openssl_pkey_get_public((string) $certificate->getCertificatePem());
        $rawSignature = base64_decode($signatureB64, true);
        if ($publicKey === false || $rawSignature === false) {
            throw new BadRequestHttpException(SpaApiError::INVALID_SIGNATURE);
        }

        // Контракт §3.5: подписывается hex-строка хэша как ASCII-байты, RSA-SHA256
        $hexHash = (string) $document->getCanonicalFileHash();
        if (openssl_verify($hexHash, $rawSignature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new BadRequestHttpException(SpaApiError::INVALID_SIGNATURE);
        }

        $signature = $this->makeSignature($document, $signer, $ipAddress, $userAgent)
            ->setLevel(SignatureLevel::ENHANCED)
            ->setAlgorithm('RSA-SHA256')
            ->setSignatureValue($signatureB64)
            ->setCertificate($certificate);

        $this->recordSignature($document, $recipient, $signature);

        return $signature;
    }

    public function decline(Document $document, User $signer, string $reason): void
    {
        if ($document->getStatus() !== DocumentStatus::ON_SIGNING) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_NOT_ON_SIGNING);
        }

        if ($this->findSignerRecipient($document, $signer) === null) {
            throw new AccessDeniedHttpException(SpaApiError::DOCUMENT_SIGNING_NOT_SIGNER);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new BadRequestHttpException(SpaApiError::REASON_REQUIRED);
        }

        $document->setStatus(DocumentStatus::REJECTED);
        // причина отказа — в action записи истории (отдельного поля контекста у DocumentHistory нет)
        $this->addHistory(
            $document,
            $signer,
            'signing_declined: ' . $reason,
            DocumentStatus::ON_SIGNING,
            DocumentStatus::REJECTED,
        );
        $this->entityManager->flush();

        // мониторинг: структурированное событие в канал signature (см. dev_docks/Readme_monitoring.txt)
        $this->logger->info('signature.declined', [
            'event' => 'declined',
            'document_id' => $document->getId(),
            'signer_id' => $signer->getId(),
        ]);

        $title = sprintf(
            '%s отказался подписывать документ «%s». Причина: %s',
            $this->fullName($signer),
            (string) $document->getName(),
            $reason,
        );
        $this->notifyAuthor($document, $signer, $title);
        $this->notifySigners($document, $this->getSigners($document), $title, $signer);
    }

    /**
     * Может ли пользователь подписать документ прямо сейчас:
     * статус ON_SIGNING, роль SIGNER, ещё не подписал, его очередь.
     * Единственный источник логики «его очередь» для презентации (T3.2).
     */
    public function canSignNow(Document $document, User $user): bool
    {
        if ($document->getStatus() !== DocumentStatus::ON_SIGNING) {
            return false;
        }

        $recipient = $this->findSignerRecipient($document, $user);

        return $recipient !== null
            && !$this->hasSigned($document, $user)
            && in_array($recipient, $this->getCurrentTurnSigners($document), true);
    }

    // ---------------------------------------------------------------- helpers

    private function assertCanSign(Document $document, User $signer): DocumentUserRecipient
    {
        if ($document->getStatus() !== DocumentStatus::ON_SIGNING) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_NOT_ON_SIGNING);
        }

        $recipient = $this->findSignerRecipient($document, $signer);
        if ($recipient === null) {
            throw new AccessDeniedHttpException(SpaApiError::DOCUMENT_SIGNING_NOT_SIGNER);
        }

        if ($this->hasSigned($document, $signer)) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_SIGNING_ALREADY_SIGNED);
        }

        if (!in_array($recipient, $this->getCurrentTurnSigners($document), true)) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_SIGNING_WRONG_TURN);
        }

        return $recipient;
    }

    private function makeSignature(Document $document, User $signer, ?string $ipAddress, ?string $userAgent): DocumentSignature
    {
        return (new DocumentSignature())
            ->setDocument($document)
            ->setSigner($signer)
            ->setDocumentHash((string) $document->getCanonicalFileHash())
            ->setSignedAt(new \DateTimeImmutable())
            ->setIpAddress($ipAddress)
            ->setUserAgent($userAgent);
    }

    private function recordSignature(Document $document, DocumentUserRecipient $recipient, DocumentSignature $signature): void
    {
        $signer = $signature->getSigner();
        $document->addSignature($signature);
        $this->entityManager->persist($signature);

        $recipient->setStatus(DocumentStatus::SIGNED);
        $recipient->setUpdatedAt(new \DateTimeImmutable());

        $this->addHistory($document, $signer, 'signed', DocumentStatus::ON_SIGNING, DocumentStatus::ON_SIGNING);

        $nextTurn = $this->getCurrentTurnSigners($document);
        if ($nextTurn === []) {
            $this->transitionToSigned($document, $signer);
        }

        $this->entityManager->flush();

        // мониторинг: структурированное событие в канал signature (см. dev_docks/Readme_monitoring.txt)
        $this->logger->info('signature.signed', [
            'event' => 'signed',
            'level' => $signature->getLevel()?->value,
            'document_id' => $document->getId(),
            'signer_id' => $signer?->getId(),
            'fully_signed' => $nextTurn === [],
        ]);

        if ($nextTurn === []) {
            $this->notifyAuthor(
                $document,
                $signer,
                sprintf('Документ «%s» подписан всеми подписантами.', (string) $document->getName()),
            );

            return;
        }

        // последовательный режим: «ваша очередь» — только тем, чья очередь наступила ПОСЛЕ этой подписи
        $signedOrder = $this->signingOrderOf($recipient);
        $next = array_filter($nextTurn, fn (DocumentUserRecipient $r): bool => $this->signingOrderOf($r) > $signedOrder);
        $this->notifySigners(
            $document,
            $next,
            sprintf('Ваша очередь подписать документ «%s».', (string) $document->getName()),
        );
    }

    /**
     * Переход ON_SIGNING → SIGNED, когда подписали все подписанты.
     */
    private function transitionToSigned(Document $document, User $actor): void
    {
        $document->setStatus(DocumentStatus::SIGNED);
        $this->addHistory($document, $actor, 'fully_signed', DocumentStatus::ON_SIGNING, DocumentStatus::SIGNED);

        // Фаза 5 (T5.1): генерация печатной формы; сбой не валит подписание —
        // форму можно сгенерировать лениво при запросе signed-form
        try {
            $this->signedFormGenerator->generate($document);
        } catch (\Throwable $e) {
            $this->logger->warning('Не удалось сгенерировать печатную форму подписанного документа', [
                'documentId' => $document->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return list<DocumentUserRecipient> */
    private function getSigners(Document $document): array
    {
        $signers = [];
        foreach ($document->getUserRecipients() as $recipient) {
            if ($recipient->getRole() === DocumentRecipientRole::SIGNER && $recipient->getUser() !== null) {
                $signers[] = $recipient;
            }
        }

        return $signers;
    }

    /**
     * Подписанты, имеющие право подписать сейчас: минимальный signingOrder
     * среди ещё не подписавших (при равных order — все параллельно).
     *
     * @return list<DocumentUserRecipient>
     */
    private function getCurrentTurnSigners(Document $document): array
    {
        $pending = array_values(array_filter(
            $this->getSigners($document),
            fn (DocumentUserRecipient $r): bool => !$this->hasSigned($document, $r->getUser()),
        ));
        if ($pending === []) {
            return [];
        }

        $minOrder = min(array_map($this->signingOrderOf(...), $pending));

        return array_values(array_filter(
            $pending,
            fn (DocumentUserRecipient $r): bool => $this->signingOrderOf($r) === $minOrder,
        ));
    }

    private function signingOrderOf(DocumentUserRecipient $recipient): int
    {
        return $recipient->getSigningOrder() ?? 1;
    }

    private function findSignerRecipient(Document $document, User $user): ?DocumentUserRecipient
    {
        foreach ($this->getSigners($document) as $recipient) {
            if ($this->isSameUser($recipient->getUser(), $user)) {
                return $recipient;
            }
        }

        return null;
    }

    private function hasSigned(Document $document, ?User $user): bool
    {
        foreach ($document->getSignatures() as $signature) {
            if ($this->isSameUser($signature->getSigner(), $user)) {
                return true;
            }
        }

        return false;
    }

    private function isSameUser(?User $a, ?User $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }

        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }

    private function addHistory(
        Document $document,
        ?User $user,
        string $action,
        DocumentStatus $oldStatus,
        DocumentStatus $newStatus,
    ): void {
        $history = new DocumentHistory();
        $history->setDocument($document);
        $history->setUser($user);
        $history->setAction($action);
        $history->setOldStatus($oldStatus);
        $history->setNewStatus($newStatus);
        $history->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($history);
    }

    // ---------------------------------------------------------- notifications

    /** @param iterable<DocumentUserRecipient> $recipients */
    private function notifySigners(Document $document, iterable $recipients, string $title, ?User $except = null): void
    {
        $documentId = $document->getId();
        if ($documentId === null) {
            return;
        }

        $link = $this->urlGenerator->generate(
            'app_view_incoming_document',
            ['id' => $documentId],
            UrlGeneratorInterface::ABSOLUTE_PATH,
        );

        $notified = [];
        foreach ($recipients as $recipient) {
            $user = $recipient->getUser();
            if ($user === null || $this->isSameUser($user, $except) || isset($notified[spl_object_id($user)])) {
                continue;
            }

            $notified[spl_object_id($user)] = true;
            $this->notificationService->notifyGeneric($user, $title, $link);
        }
    }

    private function notifyAuthor(Document $document, User $actor, string $title): void
    {
        $author = $document->getCreatedBy();
        $documentId = $document->getId();
        if ($author === null || $documentId === null || $this->isSameUser($author, $actor)) {
            return;
        }

        $link = $this->urlGenerator->generate(
            'app_view_outgoing_document',
            ['id' => $documentId],
            UrlGeneratorInterface::ABSOLUTE_PATH,
        );

        $this->notificationService->notifyGeneric($author, $title, $link);
    }

    private function fullName(User $user): string
    {
        $fullName = trim(sprintf(
            '%s %s %s',
            (string) $user->getLastname(),
            (string) $user->getFirstname(),
            (string) ($user->getPatronymic() ?? ''),
        ));

        return $fullName !== '' ? $fullName : 'Пользователь';
    }
}
