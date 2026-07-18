<?php

declare(strict_types=1);

namespace App\Tests\Controller\SpaApi\DocumentsFlow;

use App\Command\CaInitCommand;
use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentType;
use App\Entity\Document\DocumentUserRecipient;
use App\Entity\Document\UserCertificate;
use App\Entity\Organization\Organization;
use App\Entity\User\User;
use App\Enum\Document\DocumentRecipientRole;
use App\Enum\Document\DocumentStatus;
use App\Enum\Document\SignatureLevel;
use App\Service\Document\Signature\CertificateAuthorityService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Функциональные тесты SPA API подписания (Фаза 3, T3.1):
 * send-to-signing, signatures, sign/simple, sign/challenge, sign/enhanced, decline-signing.
 */
final class DocumentSignatureControllerTest extends WebTestCase
{
    private const PASSWORD = 'sign-test-password';
    private const CA_PASSPHRASE = 'test-ca-passphrase';
    private const P12_PASSWORD = 'p12-password-123';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $author;
    private User $signer1;
    private User $signer2;
    private User $outsider;
    private DocumentType $documentType;
    private Organization $organization;
    private string $canonicalDir;
    private ?string $caDir = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->canonicalDir = $container->getParameter('private_upload_dir_documents_canonical');

        $this->author = $this->findOrCreateUser('sig_ctrl_author', 'Авторов');
        $this->signer1 = $this->findOrCreateUser('sig_ctrl_signer1', 'Первый');
        $this->signer2 = $this->findOrCreateUser('sig_ctrl_signer2', 'Второй');
        $this->outsider = $this->findOrCreateUser('sig_ctrl_outsider', 'Посторонний');

        $this->documentType = $this->em->getRepository(DocumentType::class)->findOneBy([])
            ?? $this->createDocumentType();
        $this->organization = $this->em->getRepository(Organization::class)->findOneBy([])
            ?? $this->createOrganization();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();
            // Порядок важен: подписи и сертификаты ссылаются на документы/пользователей
            foreach ([
                'DELETE FROM App\Entity\Document\DocumentSignature s',
                'DELETE FROM App\Entity\Document\DocumentHistory h WHERE h.document IN (SELECT d FROM App\Entity\Document\Document d WHERE d.name LIKE :n)',
                'DELETE FROM App\Entity\Document\DocumentUserRecipient r WHERE r.document IN (SELECT d FROM App\Entity\Document\Document d WHERE d.name LIKE :n)',
                'DELETE FROM App\Entity\Document\Document d WHERE d.name LIKE :n',
                'DELETE FROM App\Entity\Document\UserCertificate c',
            ] as $dql) {
                $query = $this->em->createQuery($dql);
                if (str_contains($dql, ':n')) {
                    $query->setParameter('n', 'sig_ctrl_%');
                }
                $query->execute();
            }
        }

        if (isset($this->canonicalDir)) {
            array_map('unlink', glob($this->canonicalDir . '/sig_ctrl_*') ?: []);
        }

        if ($this->caDir !== null) {
            array_map('unlink', glob($this->caDir . '/*') ?: []);
            if (is_dir($this->caDir)) {
                rmdir($this->caDir);
            }
            $this->caDir = null;
        }

        parent::tearDown();
    }

    // ----------------------------------------------------------- авторизация

    public function testUnauthenticatedRequestGets401(): void
    {
        $document = $this->createDocument(DocumentStatus::ON_SIGNING, [[$this->signer1, 1]]);

        $this->client->request('GET', sprintf('/spa/api/documents-flow/documents/%d/signatures', $document->getId()));

        self::assertResponseStatusCodeSame(401);
    }

    public function testOutsiderGetsAccessDenied(): void
    {
        $document = $this->createDocument(DocumentStatus::ON_SIGNING, [[$this->signer1, 1]]);

        $data = $this->jsonRequest($this->outsider, 'GET', sprintf('/documents/%d/signatures', $document->getId()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(SpaApiError::ACCESS_DENIED, $data['error']);
    }

    public function testDocumentNotFound(): void
    {
        $data = $this->jsonRequest($this->author, 'POST', '/documents/99999999/send-to-signing');

        self::assertResponseStatusCodeSame(404);
        self::assertSame(SpaApiError::DOCUMENT_NOT_FOUND, $data['error']);
    }

    // ------------------------------------------------------- send-to-signing

    public function testSendToSigningSuccess(): void
    {
        $document = $this->createDocument(DocumentStatus::APPROVED, [[$this->signer1, 1]], frozen: false);

        $originalsDir = static::getContainer()->getParameter('private_upload_dir_documents_originals');
        if (!is_dir($originalsDir)) {
            mkdir($originalsDir, 0775, true);
        }
        $originalName = 'sig_ctrl_' . bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents($originalsDir . '/' . $originalName, '%PDF-1.4 test ' . random_bytes(16));
        $document->setOriginalFile($originalName);
        $this->em->flush();

        $data = $this->jsonRequest($this->author, 'POST', sprintf('/documents/%d/send-to-signing', $document->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame(DocumentStatus::ON_SIGNING->value, $data['document']['status']);
        self::assertFalse($data['document']['canSendToSigning']);

        $reloaded = $this->reloadDocument($document->getId());
        self::assertSame(DocumentStatus::ON_SIGNING, $reloaded->getStatus());
        self::assertNotNull($reloaded->getCanonicalFileHash());
        self::assertNotNull($reloaded->getVerificationCode());
    }

    public function testSendToSigningByNonAuthorForbidden(): void
    {
        $document = $this->createDocument(DocumentStatus::APPROVED, [[$this->signer1, 1]]);

        $data = $this->jsonRequest($this->signer1, 'POST', sprintf('/documents/%d/send-to-signing', $document->getId()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(SpaApiError::DOCUMENT_SIGNING_FORBIDDEN, $data['error']);
    }

    public function testSendToSigningRequiresApprovedStatus(): void
    {
        $document = $this->createDocument(DocumentStatus::NEW, [[$this->signer1, 1]]);

        $data = $this->jsonRequest($this->author, 'POST', sprintf('/documents/%d/send-to-signing', $document->getId()));

        self::assertResponseStatusCodeSame(400);
        self::assertSame(SpaApiError::DOCUMENT_NOT_APPROVED, $data['error']);
    }

    // ----------------------------------------------------------- sign/simple

    public function testSignSimpleSequentialFlow(): void
    {
        $document = $this->createDocument(
            DocumentStatus::ON_SIGNING,
            [[$this->signer1, 1], [$this->signer2, 2]],
        );
        $url = sprintf('/documents/%d/sign/simple', $document->getId());

        // второй подписант раньше первого — не его очередь
        $data = $this->jsonRequest($this->signer2, 'POST', $url, ['password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(SpaApiError::DOCUMENT_SIGNING_WRONG_TURN, $data['error']);

        // неверный пароль
        $data = $this->jsonRequest($this->signer1, 'POST', $url, ['password' => 'wrong-password']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(SpaApiError::INVALID_PASSWORD, $data['error']);

        // первый подписывает
        $data = $this->jsonRequest($this->signer1, 'POST', $url, ['password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
        self::assertSame(DocumentStatus::ON_SIGNING->value, $data['document']['status']);
        self::assertTrue($data['document']['signers'][0]['signed']);
        self::assertFalse($data['document']['allSigned']);

        // второй подписывает — документ переходит в SIGNED
        $data = $this->jsonRequest($this->signer2, 'POST', $url, ['password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
        self::assertSame(DocumentStatus::SIGNED->value, $data['document']['status']);
        self::assertTrue($data['document']['allSigned']);
    }

    public function testSignSimpleCardFlags(): void
    {
        $document = $this->createDocument(
            DocumentStatus::ON_SIGNING,
            [[$this->signer1, 1], [$this->signer2, 2]],
        );

        // карточка входящего: у первого подписанта canSign, у второго — нет (не его очередь)
        $data = $this->jsonRequest($this->signer1, 'GET', sprintf('/incoming/%d', $document->getId()));
        self::assertResponseIsSuccessful();
        self::assertTrue($data['document']['canSign']);
        self::assertTrue($data['document']['canDeclineSigning']);

        $data = $this->jsonRequest($this->signer2, 'GET', sprintf('/incoming/%d', $document->getId()));
        self::assertFalse($data['document']['canSign']);
        self::assertTrue($data['document']['canDeclineSigning']);
    }

    // ------------------------------------------------------------ signatures

    public function testSignaturesEndpointVerifiesSimpleSignature(): void
    {
        $document = $this->createDocument(DocumentStatus::ON_SIGNING, [[$this->signer1, 1]]);
        $this->jsonRequest($this->signer1, 'POST', sprintf('/documents/%d/sign/simple', $document->getId()), [
            'password' => self::PASSWORD,
        ]);
        self::assertResponseIsSuccessful();

        $data = $this->jsonRequest($this->author, 'GET', sprintf('/documents/%d/signatures', $document->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame($document->getCanonicalFileHash(), $data['documentHash']);
        self::assertSame($document->getVerificationCode(), $data['verificationCode']);
        self::assertTrue($data['allSigned']);
        self::assertCount(1, $data['signatures']);
        self::assertTrue($data['signatures'][0]['valid']);
        self::assertSame(SignatureLevel::SIMPLE->value, $data['signatures'][0]['level']);
        self::assertSame($this->signer1->getId(), $data['signatures'][0]['signer']['id']);
        self::assertNull($data['signatures'][0]['certificateSerial']);
    }

    // -------------------------------------------------------- sign/challenge

    public function testSignChallengeReturnsHashAndActiveCertificates(): void
    {
        $document = $this->createDocument(
            DocumentStatus::ON_SIGNING,
            [[$this->signer1, 1]],
            SignatureLevel::ENHANCED,
        );
        $ca = $this->makeCa();
        $active = $ca->issueCertificate($this->signer1, self::P12_PASSWORD, $this->author)->certificate;
        $revoked = $ca->issueCertificate($this->signer1, self::P12_PASSWORD, $this->author)->certificate;
        $ca->revoke($revoked, 'утерян', $this->author);
        $foreign = $ca->issueCertificate($this->signer2, self::P12_PASSWORD, $this->author)->certificate;

        $data = $this->jsonRequest($this->signer1, 'GET', sprintf('/documents/%d/sign/challenge', $document->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame($document->getCanonicalFileHash(), $data['documentHash']);
        self::assertSame('RSA-SHA256', $data['algorithm']);
        $serials = array_column($data['certificates'], 'serialNumber');
        self::assertContains($active->getSerialNumber(), $serials);
        self::assertNotContains($revoked->getSerialNumber(), $serials);
        self::assertNotContains($foreign->getSerialNumber(), $serials);
    }

    public function testSignChallengeRequiresOnSigningStatus(): void
    {
        $document = $this->createDocument(DocumentStatus::APPROVED, [[$this->signer1, 1]]);

        $data = $this->jsonRequest($this->signer1, 'GET', sprintf('/documents/%d/sign/challenge', $document->getId()));

        self::assertResponseStatusCodeSame(400);
        self::assertSame(SpaApiError::DOCUMENT_NOT_ON_SIGNING, $data['error']);
    }

    // --------------------------------------------------------- sign/enhanced

    public function testSignEnhancedSuccess(): void
    {
        $document = $this->createDocument(
            DocumentStatus::ON_SIGNING,
            [[$this->signer1, 1]],
            SignatureLevel::ENHANCED,
        );
        [$certificate, $privateKey] = $this->issueCertificateWithKey($this->signer1);

        // Контракт §3.5: подписывается hex-строка хэша как ASCII-байты, RSA-SHA256
        openssl_sign((string) $document->getCanonicalFileHash(), $rawSignature, $privateKey, OPENSSL_ALGO_SHA256);

        $data = $this->jsonRequest($this->signer1, 'POST', sprintf('/documents/%d/sign/enhanced', $document->getId()), [
            'certificateId' => $certificate->getId(),
            'signature' => base64_encode($rawSignature),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(DocumentStatus::SIGNED->value, $data['document']['status']);
        self::assertTrue($data['document']['allSigned']);

        $data = $this->jsonRequest($this->author, 'GET', sprintf('/documents/%d/signatures', $document->getId()));
        self::assertTrue($data['signatures'][0]['valid']);
        self::assertSame(SignatureLevel::ENHANCED->value, $data['signatures'][0]['level']);
        self::assertSame($certificate->getSerialNumber(), $data['signatures'][0]['certificateSerial']);
    }

    public function testSignEnhancedInvalidSignature(): void
    {
        $document = $this->createDocument(
            DocumentStatus::ON_SIGNING,
            [[$this->signer1, 1]],
            SignatureLevel::ENHANCED,
        );
        [$certificate] = $this->issueCertificateWithKey($this->signer1);

        $data = $this->jsonRequest($this->signer1, 'POST', sprintf('/documents/%d/sign/enhanced', $document->getId()), [
            'certificateId' => $certificate->getId(),
            'signature' => base64_encode(random_bytes(256)),
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(SpaApiError::INVALID_SIGNATURE, $data['error']);
    }

    public function testSignEnhancedRejectsForeignOrUnknownCertificate(): void
    {
        $document = $this->createDocument(
            DocumentStatus::ON_SIGNING,
            [[$this->signer1, 1]],
            SignatureLevel::ENHANCED,
        );
        [$foreignCertificate] = $this->issueCertificateWithKey($this->signer2);

        // чужой сертификат
        $data = $this->jsonRequest($this->signer1, 'POST', sprintf('/documents/%d/sign/enhanced', $document->getId()), [
            'certificateId' => $foreignCertificate->getId(),
            'signature' => base64_encode(random_bytes(16)),
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(SpaApiError::CERTIFICATE_NOT_FOUND, $data['error']);

        // несуществующий сертификат
        $data = $this->jsonRequest($this->signer1, 'POST', sprintf('/documents/%d/sign/enhanced', $document->getId()), [
            'certificateId' => 99999999,
            'signature' => base64_encode(random_bytes(16)),
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(SpaApiError::CERTIFICATE_NOT_FOUND, $data['error']);
    }

    // ------------------------------------------------------- decline-signing

    public function testDeclineSigning(): void
    {
        $document = $this->createDocument(DocumentStatus::ON_SIGNING, [[$this->signer1, 1]]);
        $url = sprintf('/documents/%d/decline-signing', $document->getId());

        // без причины
        $data = $this->jsonRequest($this->signer1, 'POST', $url, ['reason' => '   ']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(SpaApiError::REASON_REQUIRED, $data['error']);

        // не подписант
        $data = $this->jsonRequest($this->author, 'POST', $url, ['reason' => 'не согласен']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(SpaApiError::DOCUMENT_SIGNING_NOT_SIGNER, $data['error']);

        // отказ с причиной
        $data = $this->jsonRequest($this->signer1, 'POST', $url, ['reason' => 'не согласен']);
        self::assertResponseIsSuccessful();
        self::assertSame(DocumentStatus::REJECTED->value, $data['document']['status']);

        $reloaded = $this->reloadDocument($document->getId());
        self::assertSame(DocumentStatus::REJECTED, $reloaded->getStatus());
    }

    // --------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function jsonRequest(User $user, string $method, string $path, ?array $payload = null): array
    {
        /** @var JWTTokenManagerInterface $jwtManager */
        $jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');

        $this->client->request(
            $method,
            '/spa/api/documents-flow' . $path,
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwtManager->create($user),
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload !== null ? (string) json_encode($payload) : null,
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * @param list<array{0: User, 1: int}> $signers [пользователь, signingOrder]
     */
    private function createDocument(
        DocumentStatus $status,
        array $signers,
        SignatureLevel $level = SignatureLevel::SIMPLE,
        bool $frozen = true,
    ): Document {
        $now = new \DateTimeImmutable();

        $document = new Document();
        $document->setName('sig_ctrl_' . bin2hex(random_bytes(6)));
        $document->setDocumentType($this->documentType);
        $document->setOrganizationCreator($this->organization);
        $document->setCreatedBy($this->author);
        $document->setStatus($status);
        $document->setIsPublished(true);
        $document->setSignatureLevel($level);

        foreach ($signers as [$user, $order]) {
            $recipient = new DocumentUserRecipient();
            $recipient->setDocument($document);
            $recipient->setUser($user);
            $recipient->setRole(DocumentRecipientRole::SIGNER);
            $recipient->setStatus(DocumentStatus::NEW);
            $recipient->setSigningOrder($order);
            $recipient->setCreatedAt($now);
            $recipient->setUpdatedAt($now);
            $document->addUserRecipient($recipient);
        }

        if ($frozen) {
            if (!is_dir($this->canonicalDir)) {
                mkdir($this->canonicalDir, 0775, true);
            }
            $canonicalName = 'sig_ctrl_' . bin2hex(random_bytes(8)) . '.pdf';
            $content = '%PDF-1.4 canonical ' . random_bytes(32);
            file_put_contents($this->canonicalDir . '/' . $canonicalName, $content);
            $document->setCanonicalFile($canonicalName);
            $document->setCanonicalFileHash(hash('sha256', $content));
            $document->setVerificationCode(substr(bin2hex(random_bytes(8)), 0, 16));
        }

        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    private function reloadDocument(int $id): Document
    {
        $this->em->clear();
        $document = $this->em->find(Document::class, $id);
        self::assertInstanceOf(Document::class, $document);

        return $document;
    }

    private function makeCa(): CertificateAuthorityService
    {
        if ($this->caDir === null) {
            $this->caDir = sys_get_temp_dir() . '/ca_sig_ctrl_test_' . bin2hex(random_bytes(6));
            mkdir($this->caDir, 0700, true);
            $tester = new CommandTester(new CaInitCommand(
                $this->caDir . '/root_ca.crt',
                $this->caDir . '/root_ca.key',
                self::CA_PASSPHRASE,
            ));
            self::assertSame(0, $tester->execute([]));
        }

        return new CertificateAuthorityService(
            $this->em,
            $this->caDir . '/root_ca.crt',
            $this->caDir . '/root_ca.key',
            self::CA_PASSPHRASE,
        );
    }

    /**
     * Выпускает сертификат и возвращает [сертификат, приватный ключ из .p12] —
     * как это делает браузер, читая .p12 «с флешки».
     *
     * @return array{0: UserCertificate, 1: \OpenSSLAsymmetricKey}
     */
    private function issueCertificateWithKey(User $user): array
    {
        $result = $this->makeCa()->issueCertificate($user, self::P12_PASSWORD, $this->author);

        self::assertTrue(openssl_pkcs12_read($result->p12Binary, $parsed, self::P12_PASSWORD));
        $privateKey = openssl_pkey_get_private($parsed['pkey']);
        self::assertNotFalse($privateKey);

        return [$result->certificate, $privateKey];
    }

    private function findOrCreateUser(string $login, string $lastname): User
    {
        // Ищем в обход softdeleteable-фильтра: другие тесты (LoginControllerTest)
        // soft-удаляют всех пользователей, а unique-констрейнт по login остаётся.
        $filters = $this->em->getFilters();
        $softDeleteEnabled = $filters->isEnabled('softdeleteable');
        if ($softDeleteEnabled) {
            $filters->disable('softdeleteable');
        }
        $user = $this->em->getRepository(User::class)->findOneBy(['login' => $login]);
        if ($softDeleteEnabled) {
            $filters->enable('softdeleteable');
        }

        $user?->setDeletedAt(null);
        if (!$user instanceof User) {
            // lastname/firstname NOT NULL в схеме — заполняем обязательно
            $user = (new User())
                ->setLogin($login)
                ->setLastname($lastname)
                ->setFirstname('Тест');
            $this->em->persist($user);
        }

        $hasher = static::getContainer()->get('security.user_password_hasher');
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->flush();

        return $user;
    }

    private function createDocumentType(): DocumentType
    {
        $type = new DocumentType();
        $type->setName('sig_ctrl_type_' . bin2hex(random_bytes(4)));
        $this->em->persist($type);
        $this->em->flush();

        return $type;
    }

    private function createOrganization(): Organization
    {
        $organization = new Organization();
        $organization->setName('sig_ctrl_org_' . bin2hex(random_bytes(4)));
        $this->em->persist($organization);
        $this->em->flush();

        return $organization;
    }
}
