<?php

declare(strict_types=1);

namespace App\Tests\Service\Signature;

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
use App\Service\Document\Signature\DocumentFreezeService;
use App\Service\Document\Signature\SignedFormGenerator;
use App\Service\Document\Signature\SigningService;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SigningServiceTest extends TestCase
{
    private const HASH = 'a3f5c9d1e7b2a3f5c9d1e7b2a3f5c9d1e7b2a3f5c9d1e7b2a3f5c9d1e7b2a3f5';
    private const PASSWORD = 'secret-password';

    /** @var list<object> */
    private array $persisted = [];

    /** @var list<array{login: string, title: string}> уведомления notifyGeneric */
    private array $notifications = [];

    private bool $passwordValid = true;
    private bool $freezeCalled = false;

    protected function setUp(): void
    {
        $this->persisted = [];
        $this->notifications = [];
        $this->passwordValid = true;
        $this->freezeCalled = false;
    }

    // ------------------------------------------------------- sendToSigning

    public function testSendToSigningFreezesAndMovesToOnSigning(): void
    {
        $author = $this->makeUser('author');
        $document = $this->makeDocument(DocumentStatus::APPROVED, $author);
        $this->addSigner($document, $this->makeUser('signer1'), 1);

        $this->makeService()->sendToSigning($document, $author);

        self::assertTrue($this->freezeCalled);
        self::assertSame(DocumentStatus::ON_SIGNING, $document->getStatus());
        self::assertNotNull($document->getSentToSigningAt());
        self::assertSame(['sent_to_signing'], $this->historyActions());
    }

    public function testSendToSigningByNotAuthorIsForbidden(): void
    {
        $document = $this->makeDocument(DocumentStatus::APPROVED, $this->makeUser('author'));
        $this->addSigner($document, $this->makeUser('signer1'), 1);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage(SpaApiError::DOCUMENT_SIGNING_FORBIDDEN);

        $this->makeService()->sendToSigning($document, $this->makeUser('stranger'));
    }

    public function testSendToSigningRequiresApprovedStatus(): void
    {
        $author = $this->makeUser('author');
        $document = $this->makeDocument(DocumentStatus::PENDING_APPROVAL, $author);
        $this->addSigner($document, $this->makeUser('signer1'), 1);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::DOCUMENT_NOT_APPROVED);

        $this->makeService()->sendToSigning($document, $author);
    }

    public function testSendToSigningRequiresAtLeastOneSigner(): void
    {
        $author = $this->makeUser('author');
        $document = $this->makeDocument(DocumentStatus::APPROVED, $author);

        // получатель без роли SIGNER не считается подписантом
        $recipient = new DocumentUserRecipient();
        $recipient->setUser($this->makeUser('executor'));
        $recipient->setRole(DocumentRecipientRole::EXECUTOR);
        $recipient->setStatus(DocumentStatus::NEW);
        $document->addUserRecipient($recipient);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::DOCUMENT_NO_SIGNERS);

        $this->makeService()->sendToSigning($document, $author);
    }

    public function testSendToSigningParallelNotifiesAllSignersSequentialOnlyFirst(): void
    {
        // параллельный: у всех order=1 — уведомлены оба
        $author = $this->makeUser('author');
        $document = $this->makeDocument(DocumentStatus::APPROVED, $author);
        $this->addSigner($document, $this->makeUser('p1'), 1);
        $this->addSigner($document, $this->makeUser('p2'), 1);
        $this->makeService()->sendToSigning($document, $author);
        self::assertSame(['p1', 'p2'], $this->notifiedLogins());

        // последовательный: уведомлён только первый
        $this->notifications = [];
        $document2 = $this->makeDocument(DocumentStatus::APPROVED, $author);
        $this->addSigner($document2, $this->makeUser('s1'), 1);
        $this->addSigner($document2, $this->makeUser('s2'), 2);
        $this->makeService()->sendToSigning($document2, $author);
        self::assertSame(['s1'], $this->notifiedLogins());
        self::assertStringContainsString('Вам на подпись', $this->notifications[0]['title']);
    }

    // ----------------------------------------------------------- signSimple

    public function testSignSimpleCreatesSignatureAndMarksRecipientSigned(): void
    {
        $signer = $this->makeUser('signer1');
        $document = $this->makeOnSigningDocument([$signer]);
        $recipient = $document->getUserRecipients()->first();

        $signature = $this->makeService()->signSimple($document, $signer, self::PASSWORD, '10.0.0.1', 'PHPUnit');

        self::assertSame(SignatureLevel::SIMPLE, $signature->getLevel());
        self::assertSame('password-confirmation', $signature->getAlgorithm());
        self::assertSame(self::HASH, $signature->getDocumentHash());
        self::assertNull($signature->getSignatureValue());
        self::assertNull($signature->getCertificate());
        self::assertSame('10.0.0.1', $signature->getIpAddress());
        self::assertSame('PHPUnit', $signature->getUserAgent());
        self::assertNotNull($signature->getSignedAt());
        self::assertSame(DocumentStatus::SIGNED, $recipient->getStatus());
        self::assertContains($signature, $this->persisted);
    }

    public function testSignSimpleRequiresOnSigningStatus(): void
    {
        $signer = $this->makeUser('signer1');
        $document = $this->makeDocument(DocumentStatus::APPROVED, $this->makeUser('author'));
        $this->addSigner($document, $signer, 1);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::DOCUMENT_NOT_ON_SIGNING);

        $this->makeService()->signSimple($document, $signer, self::PASSWORD);
    }

    public function testSignSimpleByNonSignerIsRejected(): void
    {
        $document = $this->makeOnSigningDocument([$this->makeUser('signer1')]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage(SpaApiError::DOCUMENT_SIGNING_NOT_SIGNER);

        $this->makeService()->signSimple($document, $this->makeUser('stranger'), self::PASSWORD);
    }

    public function testSignSimpleTwiceIsAlreadySigned(): void
    {
        $signer = $this->makeUser('signer1');
        $document = $this->makeOnSigningDocument([$signer, $this->makeUser('signer2')]);
        $service = $this->makeService();
        $service->signSimple($document, $signer, self::PASSWORD);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::DOCUMENT_SIGNING_ALREADY_SIGNED);

        $service->signSimple($document, $signer, self::PASSWORD);
    }

    public function testSignSimpleOnEnhancedLevelDocumentIsInsufficient(): void
    {
        $signer = $this->makeUser('signer1');
        $document = $this->makeOnSigningDocument([$signer], SignatureLevel::ENHANCED);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::DOCUMENT_SIGNING_LEVEL_INSUFFICIENT);

        $this->makeService()->signSimple($document, $signer, self::PASSWORD);
    }

    public function testSignSimpleWithInvalidPassword(): void
    {
        $signer = $this->makeUser('signer1');
        $document = $this->makeOnSigningDocument([$signer]);
        $this->passwordValid = false;

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::INVALID_PASSWORD);

        $this->makeService()->signSimple($document, $signer, 'wrong');
    }

    // --------------------------------------------------------------- очередь

    public function testSequentialSecondCannotSignBeforeFirst(): void
    {
        $first = $this->makeUser('first');
        $second = $this->makeUser('second');
        $document = $this->makeOnSigningDocument([]);
        $this->addSigner($document, $first, 1);
        $this->addSigner($document, $second, 2);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::DOCUMENT_SIGNING_WRONG_TURN);

        $this->makeService()->signSimple($document, $second, self::PASSWORD);
    }

    public function testSequentialSecondCanSignAfterFirstAndDocumentBecomesSigned(): void
    {
        $first = $this->makeUser('first');
        $second = $this->makeUser('second');
        $document = $this->makeOnSigningDocument([]);
        $this->addSigner($document, $first, 1);
        $this->addSigner($document, $second, 2);
        $service = $this->makeService();

        $service->signSimple($document, $first, self::PASSWORD);
        self::assertSame(DocumentStatus::ON_SIGNING, $document->getStatus());
        // «ваша очередь» — следующему по порядку
        self::assertSame(['second'], $this->notifiedLogins());
        self::assertStringContainsString('Ваша очередь', $this->notifications[0]['title']);

        $service->signSimple($document, $second, self::PASSWORD);

        self::assertSame(DocumentStatus::SIGNED, $document->getStatus());
        self::assertSame(['signed', 'signed', 'fully_signed'], $this->historyActions());
        // «документ подписан» — автору
        self::assertSame(['second', 'author'], $this->notifiedLogins());
        self::assertStringContainsString('подписан всеми', $this->notifications[1]['title']);
    }

    public function testParallelSignersMaySignInAnyOrder(): void
    {
        $first = $this->makeUser('first');
        $second = $this->makeUser('second');
        $document = $this->makeOnSigningDocument([]);
        $this->addSigner($document, $first, 1);
        $this->addSigner($document, $second, 1);
        $service = $this->makeService();

        $service->signSimple($document, $second, self::PASSWORD);
        self::assertSame(DocumentStatus::ON_SIGNING, $document->getStatus());
        // при параллельном режиме повторного «ваша очередь» нет
        self::assertSame([], $this->notifiedLogins());

        $service->signSimple($document, $first, self::PASSWORD);
        self::assertSame(DocumentStatus::SIGNED, $document->getStatus());
    }

    // --------------------------------------------------------- signEnhanced

    public function testSignEnhancedWithValidSignature(): void
    {
        $signer = $this->makeUser('signer1');
        $document = $this->makeOnSigningDocument([$signer], SignatureLevel::ENHANCED);
        [$key, $certificate] = $this->makeKeyAndCertificate($signer);

        $signature = $this->makeService()->signEnhanced(
            $document,
            $signer,
            $this->signHash($key),
            $certificate,
        );

        self::assertSame(SignatureLevel::ENHANCED, $signature->getLevel());
        self::assertSame('RSA-SHA256', $signature->getAlgorithm());
        self::assertSame($certificate, $signature->getCertificate());
        self::assertNotNull($signature->getSignatureValue());
        self::assertSame(DocumentStatus::SIGNED, $document->getStatus());
    }

    public function testSignEnhancedIsAllowedOnSimpleLevelDocument(): void
    {
        $signer = $this->makeUser('signer1');
        $document = $this->makeOnSigningDocument([$signer], SignatureLevel::SIMPLE);
        [$key, $certificate] = $this->makeKeyAndCertificate($signer);

        $signature = $this->makeService()->signEnhanced($document, $signer, $this->signHash($key), $certificate);

        self::assertSame(SignatureLevel::ENHANCED, $signature->getLevel());
    }

    public function testSignEnhancedWithForeignCertificateIsRejected(): void
    {
        $signer = $this->makeUser('signer1');
        $document = $this->makeOnSigningDocument([$signer], SignatureLevel::ENHANCED);
        [$key, $certificate] = $this->makeKeyAndCertificate($this->makeUser('other'));

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage(SpaApiError::CERTIFICATE_NOT_FOUND);

        $this->makeService()->signEnhanced($document, $signer, $this->signHash($key), $certificate);
    }

    public function testSignEnhancedWithRevokedCertificate(): void
    {
        $signer = $this->makeUser('signer1');
        $document = $this->makeOnSigningDocument([$signer], SignatureLevel::ENHANCED);
        [$key, $certificate] = $this->makeKeyAndCertificate($signer);
        $certificate->setStatus(CertificateStatus::REVOKED)
            ->setRevokedAt(new \DateTimeImmutable())
            ->setRevocationReason('Утеря носителя');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::CERTIFICATE_REVOKED);

        $this->makeService()->signEnhanced($document, $signer, $this->signHash($key), $certificate);
    }

    public function testSignEnhancedWithExpiredCertificate(): void
    {
        $signer = $this->makeUser('signer1');
        $document = $this->makeOnSigningDocument([$signer], SignatureLevel::ENHANCED);
        [$key, $certificate] = $this->makeKeyAndCertificate($signer);
        $certificate->setValidFrom(new \DateTimeImmutable('-2 years'))
            ->setValidTo(new \DateTimeImmutable('-1 year'));

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::CERTIFICATE_EXPIRED);

        $this->makeService()->signEnhanced($document, $signer, $this->signHash($key), $certificate);
    }

    public function testSignEnhancedWithForeignKeySignatureIsInvalid(): void
    {
        $signer = $this->makeUser('signer1');
        $document = $this->makeOnSigningDocument([$signer], SignatureLevel::ENHANCED);
        [, $certificate] = $this->makeKeyAndCertificate($signer);
        [$foreignKey] = $this->makeKeyAndCertificate($signer);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::INVALID_SIGNATURE);

        // подпись сделана чужим ключом — не соответствует сертификату
        $this->makeService()->signEnhanced($document, $signer, $this->signHash($foreignKey), $certificate);
    }

    // -------------------------------------------------------------- decline

    public function testDeclineMovesDocumentToRejectedWithReasonInHistory(): void
    {
        $signer = $this->makeUser('signer1');
        $document = $this->makeOnSigningDocument([$signer, $this->makeUser('signer2')]);

        $this->makeService()->decline($document, $signer, 'Не согласен с пунктом 3');

        self::assertSame(DocumentStatus::REJECTED, $document->getStatus());
        $actions = $this->historyActions();
        self::assertCount(1, $actions);
        self::assertStringStartsWith('signing_declined', $actions[0]);
        self::assertStringContainsString('Не согласен с пунктом 3', $actions[0]);
        // уведомления: автору и остальным подписантам (кроме отказавшегося)
        self::assertSame(['author', 'signer2'], $this->notifiedLogins());
        self::assertStringContainsString('отказался подписывать', $this->notifications[0]['title']);
        self::assertStringContainsString('Не согласен с пунктом 3', $this->notifications[0]['title']);
    }

    public function testDeclineRequiresOnSigningStatus(): void
    {
        $signer = $this->makeUser('signer1');
        $document = $this->makeDocument(DocumentStatus::APPROVED, $this->makeUser('author'));
        $this->addSigner($document, $signer, 1);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::DOCUMENT_NOT_ON_SIGNING);

        $this->makeService()->decline($document, $signer, 'причина');
    }

    public function testDeclineByNonSignerIsRejected(): void
    {
        $document = $this->makeOnSigningDocument([$this->makeUser('signer1')]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage(SpaApiError::DOCUMENT_SIGNING_NOT_SIGNER);

        $this->makeService()->decline($document, $this->makeUser('stranger'), 'причина');
    }

    public function testDeclineRequiresReason(): void
    {
        $signer = $this->makeUser('signer1');
        $document = $this->makeOnSigningDocument([$signer]);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::REASON_REQUIRED);

        $this->makeService()->decline($document, $signer, '   ');
    }

    // -------------------------------------------------------------- helpers

    private function makeService(): SigningService
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        $freeze = $this->createStub(DocumentFreezeService::class);
        $freeze->method('freeze')->willReturnCallback(function (Document $document): void {
            $this->freezeCalled = true;
            $document->setCanonicalFile('frozen.pdf');
            $document->setCanonicalFileHash(self::HASH);
            $document->setVerificationCode('abcdef0123456789');
        });

        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $hasher->method('isPasswordValid')->willReturnCallback(
            fn (): bool => $this->passwordValid,
        );

        $notifications = $this->createStub(NotificationService::class);
        $notifications->method('notifyGeneric')->willReturnCallback(function (User $user, string $title): void {
            $this->notifications[] = ['login' => (string) $user->getLogin(), 'title' => $title];
        });

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/documents/1');

        // реальный генератор с несуществующей директорией: generate() бросит исключение,
        // а SigningService по контракту его глотает — подписание не зависит от печатной формы
        $signedFormGenerator = new SignedFormGenerator($em, '/nonexistent_canonical', '/nonexistent_forms', 'http://localhost');

        return new SigningService(
            $em,
            $freeze,
            $hasher,
            $notifications,
            $urlGenerator,
            $signedFormGenerator,
            new NullLogger(),
        );
    }

    private function makeUser(string $login): User
    {
        $user = new User();
        $user->setLogin($login);

        return $user;
    }

    private function makeDocument(DocumentStatus $status, User $author): Document
    {
        $document = (new Document())
            ->setName('Договор поставки')
            ->setStatus($status)
            ->setCreatedBy($author);

        // id нужен для генерации ссылок в уведомлениях
        $ref = new \ReflectionProperty(Document::class, 'id');
        $ref->setValue($document, random_int(1, PHP_INT_MAX));

        return $document;
    }

    /** @param list<User> $signers все с signingOrder=1 (параллельно) */
    private function makeOnSigningDocument(array $signers, ?SignatureLevel $level = SignatureLevel::SIMPLE): Document
    {
        $document = $this->makeDocument(DocumentStatus::ON_SIGNING, $this->makeUser('author'))
            ->setSignatureLevel($level)
            ->setCanonicalFile('frozen.pdf')
            ->setCanonicalFileHash(self::HASH)
            ->setSentToSigningAt(new \DateTimeImmutable());

        foreach ($signers as $signer) {
            $this->addSigner($document, $signer, 1);
        }

        return $document;
    }

    private function addSigner(Document $document, User $user, int $order): DocumentUserRecipient
    {
        $recipient = new DocumentUserRecipient();
        $recipient->setUser($user);
        $recipient->setRole(DocumentRecipientRole::SIGNER);
        $recipient->setStatus(DocumentStatus::NEW);
        $recipient->setSigningOrder($order);
        $document->addUserRecipient($recipient);

        return $recipient;
    }

    /**
     * Самоподписанный сертификат подписанта (для криптопроверки цепочка до УЦ не нужна).
     *
     * @return array{0: \OpenSSLAsymmetricKey, 1: UserCertificate}
     */
    private function makeKeyAndCertificate(User $owner): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);

        $csr = openssl_csr_new(['commonName' => (string) $owner->getLogin()], $key, ['digest_alg' => 'sha256']);
        self::assertNotFalse($csr);

        $x509 = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        self::assertNotFalse($x509);
        openssl_x509_export($x509, $pem);

        $info = openssl_x509_parse($x509);
        self::assertIsArray($info);

        $certificate = (new UserCertificate())
            ->setUser($owner)
            ->setSerialNumber(strtoupper((string) $info['serialNumberHex']))
            ->setSubjectDn((string) $info['name'])
            ->setCertificatePem($pem)
            ->setValidFrom((new \DateTimeImmutable())->setTimestamp((int) $info['validFrom_time_t']))
            ->setValidTo((new \DateTimeImmutable())->setTimestamp((int) $info['validTo_time_t']))
            ->setStatus(CertificateStatus::ACTIVE);

        return [$key, $certificate];
    }

    /** Контракт §3.5: подписывается hex-строка хэша как ASCII-байты, RSA-SHA256. */
    private function signHash(\OpenSSLAsymmetricKey $key): string
    {
        self::assertTrue(openssl_sign(self::HASH, $rawSignature, $key, OPENSSL_ALGO_SHA256));

        return base64_encode($rawSignature);
    }

    /** @return list<string> action-записи DocumentHistory в порядке создания */
    private function historyActions(): array
    {
        $actions = [];
        foreach ($this->persisted as $entity) {
            if ($entity instanceof DocumentHistory) {
                $actions[] = (string) $entity->getAction();
            }
        }

        return $actions;
    }

    /** @return list<string> */
    private function notifiedLogins(): array
    {
        return array_column($this->notifications, 'login');
    }
}
