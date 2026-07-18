<?php

declare(strict_types=1);

namespace App\Tests\Service\Signature;

use App\Entity\Document\Document;
use App\Entity\Document\DocumentSignature;
use App\Entity\Document\UserCertificate;
use App\Entity\User\User;
use App\Enum\Document\CertificateStatus;
use App\Enum\Document\SignatureLevel;
use App\Repository\Document\DocumentRepository;
use App\Service\Document\Signature\SignatureVerificationService;
use PHPUnit\Framework\TestCase;

final class SignatureVerificationServiceTest extends TestCase
{
    private string $canonicalDir;

    protected function setUp(): void
    {
        $this->canonicalDir = sys_get_temp_dir() . '/canonical_test_' . bin2hex(random_bytes(6));
        mkdir($this->canonicalDir, 0700, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->canonicalDir . '/*') ?: []);
        if (is_dir($this->canonicalDir)) {
            rmdir($this->canonicalDir);
        }
    }

    public function testValidEnhancedSignatureIsValid(): void
    {
        $document = $this->makeDocument('%PDF-1.4 canonical content');
        [$key, $certificate] = $this->makeKeyAndCertificate();
        $document->addSignature($this->makeEnhancedSignature($document, $key, $certificate));

        $result = $this->makeService()->verifyDocument($document);

        self::assertTrue($result->allValid);
        self::assertSame($document->getCanonicalFileHash(), $result->actualHash);
        self::assertCount(1, $result->signatures);
        self::assertTrue($result->signatures[0]->valid);
        self::assertNull($result->signatures[0]->reason);
    }

    public function testTamperedFileGivesHashMismatch(): void
    {
        $document = $this->makeDocument('%PDF-1.4 original');
        [$key, $certificate] = $this->makeKeyAndCertificate();
        $document->addSignature($this->makeEnhancedSignature($document, $key, $certificate));

        // подменяем канонический файл после подписания
        file_put_contents($this->canonicalDir . \DIRECTORY_SEPARATOR . $document->getCanonicalFile(), '%PDF-1.4 tampered');

        $result = $this->makeService()->verifyDocument($document);

        self::assertFalse($result->allValid);
        self::assertFalse($result->signatures[0]->valid);
        self::assertSame('hash_mismatch', $result->signatures[0]->reason);
    }

    public function testSignatureByForeignKeyIsInvalidSignature(): void
    {
        $document = $this->makeDocument('%PDF-1.4 content');
        [, $certificate] = $this->makeKeyAndCertificate();
        [$foreignKey] = $this->makeKeyAndCertificate();

        // подпись сделана чужим ключом, сертификат — «свой»
        $document->addSignature($this->makeEnhancedSignature($document, $foreignKey, $certificate));

        $result = $this->makeService()->verifyDocument($document);

        self::assertFalse($result->allValid);
        self::assertSame('invalid_signature', $result->signatures[0]->reason);
    }

    public function testCertificateRevokedBeforeSigningInvalidatesSignature(): void
    {
        $document = $this->makeDocument('%PDF-1.4 content');
        [$key, $certificate] = $this->makeKeyAndCertificate();

        // отзыв сейчас, подписание — через день после отзыва (в пределах срока действия)
        $signedAt = new \DateTimeImmutable('+1 day');
        $certificate->setStatus(CertificateStatus::REVOKED)
            ->setRevokedAt(new \DateTimeImmutable())
            ->setRevocationReason('Утеря носителя');

        $document->addSignature($this->makeEnhancedSignature($document, $key, $certificate, $signedAt));

        $result = $this->makeService()->verifyDocument($document);

        self::assertFalse($result->allValid);
        self::assertSame('certificate_revoked', $result->signatures[0]->reason);
    }

    public function testCertificateRevokedAfterSigningKeepsSignatureValidWithMark(): void
    {
        $document = $this->makeDocument('%PDF-1.4 content');
        [$key, $certificate] = $this->makeKeyAndCertificate();

        // подписание сейчас, отзыв — через день после подписания
        $signedAt = new \DateTimeImmutable();
        $certificate->setStatus(CertificateStatus::REVOKED)
            ->setRevokedAt(new \DateTimeImmutable('+1 day'))
            ->setRevocationReason('Плановая ротация');

        $document->addSignature($this->makeEnhancedSignature($document, $key, $certificate, $signedAt));

        $result = $this->makeService()->verifyDocument($document);

        self::assertTrue($result->allValid);
        self::assertTrue($result->signatures[0]->valid);
        self::assertTrue($result->signatures[0]->details['revokedAfterSigning'] ?? false);
    }

    public function testCertificateExpiredAtSigningMomentIsInvalid(): void
    {
        $document = $this->makeDocument('%PDF-1.4 content');
        [$key, $certificate] = $this->makeKeyAndCertificate();

        // подписание позже validTo сертификата
        $signedAt = $certificate->getValidTo()->modify('+1 day');
        $document->addSignature($this->makeEnhancedSignature($document, $key, $certificate, $signedAt));

        $result = $this->makeService()->verifyDocument($document);

        self::assertFalse($result->allValid);
        self::assertSame('certificate_expired', $result->signatures[0]->reason);
    }

    public function testSimpleSignatureWithMatchingHashIsValid(): void
    {
        $document = $this->makeDocument('%PDF-1.4 content');
        $document->addSignature($this->makeSimpleSignature($document, (string) $document->getCanonicalFileHash()));

        $result = $this->makeService()->verifyDocument($document);

        self::assertTrue($result->allValid);
    }

    public function testSimpleSignatureWithMismatchingHashIsInvalid(): void
    {
        $document = $this->makeDocument('%PDF-1.4 content');
        $document->addSignature($this->makeSimpleSignature($document, str_repeat('0', 64)));

        $result = $this->makeService()->verifyDocument($document);

        self::assertFalse($result->allValid);
        self::assertSame('hash_mismatch', $result->signatures[0]->reason);
    }

    public function testDocumentWithoutSignaturesIsNotAllValid(): void
    {
        $document = $this->makeDocument('%PDF-1.4 content');

        $result = $this->makeService()->verifyDocument($document);

        self::assertFalse($result->allValid);
        self::assertSame([], $result->signatures);
    }

    public function testFindByFileContentLooksUpBySha256(): void
    {
        $binary = '%PDF-1.4 stored content';
        $document = new Document();

        $repository = $this->createMock(DocumentRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['canonicalFileHash' => hash('sha256', $binary)])
            ->willReturn($document);

        $service = new SignatureVerificationService($repository, $this->canonicalDir);

        self::assertSame($document, $service->findByFileContent($binary));
    }

    private function makeService(): SignatureVerificationService
    {
        return new SignatureVerificationService($this->createStub(DocumentRepository::class), $this->canonicalDir);
    }

    private function makeDocument(string $content): Document
    {
        // canonicalFile хранит ТОЛЬКО имя файла — как пишет DocumentFreezeService
        $fileName = bin2hex(random_bytes(16)) . '.pdf';
        file_put_contents($this->canonicalDir . \DIRECTORY_SEPARATOR . $fileName, $content);

        return (new Document())
            ->setCanonicalFile($fileName)
            ->setCanonicalFileHash(hash('sha256', $content));
    }

    /**
     * Самоподписанный сертификат — для криптопроверки цепочка до УЦ не нужна.
     *
     * @return array{0: \OpenSSLAsymmetricKey, 1: UserCertificate}
     */
    private function makeKeyAndCertificate(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);

        $csr = openssl_csr_new(['commonName' => 'signer'], $key, ['digest_alg' => 'sha256']);
        self::assertNotFalse($csr);

        $x509 = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        self::assertNotFalse($x509);
        openssl_x509_export($x509, $pem);

        $info = openssl_x509_parse($x509);
        self::assertIsArray($info);

        $certificate = (new UserCertificate())
            ->setUser($this->makeSigner())
            ->setSerialNumber(strtoupper((string) $info['serialNumberHex']))
            ->setSubjectDn((string) $info['name'])
            ->setCertificatePem($pem)
            ->setValidFrom((new \DateTimeImmutable())->setTimestamp((int) $info['validFrom_time_t']))
            ->setValidTo((new \DateTimeImmutable())->setTimestamp((int) $info['validTo_time_t']))
            ->setStatus(CertificateStatus::ACTIVE);

        return [$key, $certificate];
    }

    private function makeEnhancedSignature(
        Document $document,
        \OpenSSLAsymmetricKey $key,
        UserCertificate $certificate,
        ?\DateTimeImmutable $signedAt = null,
    ): DocumentSignature {
        $hexHash = (string) $document->getCanonicalFileHash();

        // Контракт §3.5: подписывается hex-строка хэша как ASCII-байты
        self::assertTrue(openssl_sign($hexHash, $rawSignature, $key, OPENSSL_ALGO_SHA256));

        return (new DocumentSignature())
            ->setDocument($document)
            ->setSigner($certificate->getUser() ?? $this->makeSigner())
            ->setLevel(SignatureLevel::ENHANCED)
            ->setDocumentHash($hexHash)
            ->setSignatureValue(base64_encode($rawSignature))
            ->setCertificate($certificate)
            ->setAlgorithm('RSA-SHA256')
            ->setSignedAt($signedAt ?? new \DateTimeImmutable());
    }

    private function makeSimpleSignature(Document $document, string $documentHash): DocumentSignature
    {
        return (new DocumentSignature())
            ->setDocument($document)
            ->setSigner($this->makeSigner())
            ->setLevel(SignatureLevel::SIMPLE)
            ->setDocumentHash($documentHash)
            ->setAlgorithm('password-confirmation')
            ->setSignedAt(new \DateTimeImmutable());
    }

    private function makeSigner(): User
    {
        $user = new User();
        $user->setLogin('signer');

        return $user;
    }
}
