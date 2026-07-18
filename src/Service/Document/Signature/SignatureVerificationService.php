<?php

declare(strict_types=1);

namespace App\Service\Document\Signature;

use App\DTO\Document\Signature\DocumentVerificationResult;
use App\DTO\Document\Signature\SignatureVerificationResult;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentSignature;
use App\Enum\Document\CertificateStatus;
use App\Enum\Document\SignatureLevel;
use App\Repository\Document\DocumentRepository;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Проверка подписей документа.
 *
 * Правила:
 * - хэш канонического файла пересчитывается и сверяется с documentHash каждой подписи;
 * - для УНЭП криптопроверка openssl_verify: подписана HEX-СТРОКА хэша как ASCII-байты (контракт §3.5);
 * - сертификат должен быть действителен НА МОМЕНТ signedAt; отзыв ПОСЛЕ подписания
 *   подпись не инвалидирует, но помечается в details.
 */
#[WithMonologChannel('signature')]
final class SignatureVerificationService
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        #[Autowire('%private_upload_dir_documents_canonical%')]
        private readonly string $documentsCanonicalDir,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function verifyDocument(Document $document): DocumentVerificationResult
    {
        $actualHash = null;
        $canonicalFile = $document->getCanonicalFile();
        if ($canonicalFile !== null) {
            // canonicalFile хранит ТОЛЬКО имя файла (см. DocumentFreezeService)
            $canonicalPath = $this->documentsCanonicalDir . \DIRECTORY_SEPARATOR . basename($canonicalFile);
            if (is_file($canonicalPath)) {
                $actualHash = hash_file('sha256', $canonicalPath) ?: null;
            }
        }

        $results = [];
        foreach ($document->getSignatures() as $signature) {
            $result = $this->verifySignature($signature, $actualHash);
            if (!$result->valid) {
                // мониторинг: структурированное событие в канал signature (см. dev_docks/Readme_monitoring.txt)
                $this->logger->warning('signature.verification_failed', [
                    'event' => 'verification_failed',
                    'reason' => $result->reason,
                    'document_id' => $document->getId(),
                    'signature_id' => $signature->getId(),
                ]);
            }
            $results[] = $result;
        }

        $allValid = $results !== []
            && array_all($results, static fn (SignatureVerificationResult $r): bool => $r->valid);

        return new DocumentVerificationResult($actualHash, $results, $allValid);
    }

    public function findByFileContent(string $binary): ?Document
    {
        return $this->documentRepository->findOneBy(['canonicalFileHash' => hash('sha256', $binary)]);
    }

    private function verifySignature(DocumentSignature $signature, ?string $actualHash): SignatureVerificationResult
    {
        $documentHash = (string) $signature->getDocumentHash();

        if ($actualHash === null || !hash_equals($actualHash, $documentHash)) {
            return new SignatureVerificationResult($signature, false, 'hash_mismatch');
        }

        if ($signature->getLevel() === SignatureLevel::SIMPLE) {
            return new SignatureVerificationResult($signature, true);
        }

        // УНЭП
        $certificate = $signature->getCertificate();
        $signedAt = $signature->getSignedAt();
        $signatureValue = $signature->getSignatureValue();
        if ($certificate === null || $signedAt === null || $signatureValue === null) {
            return new SignatureVerificationResult($signature, false, 'invalid_signature');
        }

        $details = ['certificateSerial' => $certificate->getSerialNumber()];

        if ($signedAt < $certificate->getValidFrom() || $signedAt > $certificate->getValidTo()) {
            return new SignatureVerificationResult($signature, false, 'certificate_expired', $details);
        }

        if ($certificate->getStatus() === CertificateStatus::REVOKED) {
            $revokedAt = $certificate->getRevokedAt();
            $details['revokedAt'] = $revokedAt?->format(\DateTimeInterface::ATOM);

            if ($revokedAt === null || $revokedAt <= $signedAt) {
                // отозван до момента подписания — подпись недействительна
                return new SignatureVerificationResult($signature, false, 'certificate_revoked', $details);
            }

            // отозван после подписания — подпись остаётся действительной, но помечаем
            $details['revokedAfterSigning'] = true;
        }

        $publicKey = openssl_pkey_get_public((string) $certificate->getCertificatePem());
        $rawSignature = base64_decode($signatureValue, true);
        if ($publicKey === false || $rawSignature === false) {
            return new SignatureVerificationResult($signature, false, 'invalid_signature', $details);
        }

        // Контракт §3.5: подписывается hex-строка хэша как ASCII-байты, RSA-SHA256
        $valid = openssl_verify($documentHash, $rawSignature, $publicKey, OPENSSL_ALGO_SHA256) === 1;

        return new SignatureVerificationResult($signature, $valid, $valid ? null : 'invalid_signature', $details);
    }
}
