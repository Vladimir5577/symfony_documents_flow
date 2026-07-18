<?php

declare(strict_types=1);

namespace App\Tests\Controller\Document;

use App\Command\CaInitCommand;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentSignature;
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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Функциональные тесты легаси Twig-интерфейса подписания (Фаза 4, T4.1):
 * сессионная аутентификация (loginUser) + CSRF из отрендеренных форм.
 */
final class DocumentSigningPageControllerTest extends WebTestCase
{
    private const PASSWORD = 'twig-sign-password';
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

        $this->author = $this->findOrCreateUser('twig_sign_author', 'Авторов');
        $this->signer1 = $this->findOrCreateUser('twig_sign_signer1', 'Первый');
        $this->signer2 = $this->findOrCreateUser('twig_sign_signer2', 'Второй');
        $this->outsider = $this->findOrCreateUser('twig_sign_outsider', 'Посторонний');

        $this->documentType = $this->em->getRepository(DocumentType::class)->findOneBy([])
            ?? $this->createDocumentType();
        $this->organization = $this->em->getRepository(Organization::class)->findOneBy([])
            ?? $this->createOrganization();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();
            foreach ([
                'DELETE FROM App\Entity\Document\DocumentSignature s WHERE s.document IN (SELECT d FROM App\Entity\Document\Document d WHERE d.name LIKE :n)',
                'DELETE FROM App\Entity\Document\DocumentHistory h WHERE h.document IN (SELECT d FROM App\Entity\Document\Document d WHERE d.name LIKE :n)',
                'DELETE FROM App\Entity\Document\DocumentUserRecipient r WHERE r.document IN (SELECT d FROM App\Entity\Document\Document d WHERE d.name LIKE :n)',
                'DELETE FROM App\Entity\Document\Document d WHERE d.name LIKE :n',
                'DELETE FROM App\Entity\Document\UserCertificate c',
            ] as $dql) {
                $query = $this->em->createQuery($dql);
                if (str_contains($dql, ':n')) {
                    $query->setParameter('n', 'twig_sign_%');
                }
                $query->execute();
            }
        }

        if (isset($this->canonicalDir)) {
            array_map('unlink', glob($this->canonicalDir . '/twig_sign_*') ?: []);
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

    // -------------------------------------------------------- страница /sign

    public function testSignPageOpensForSigner(): void
    {
        $document = $this->createDocument(DocumentStatus::ON_SIGNING, [[$this->signer1, 1]]);

        $this->client->loginUser($this->signer1);
        $this->client->request('GET', sprintf('/document/%d/sign', $document->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Подписание документа');
        // ПЭП-форма доступна (уровень документа simple)
        self::assertSelectorExists(sprintf('form[action$="/document/%d/sign/simple"]', $document->getId()));
    }

    public function testSignPageForbiddenForOutsider(): void
    {
        $document = $this->createDocument(DocumentStatus::ON_SIGNING, [[$this->signer1, 1]]);

        $this->client->loginUser($this->outsider);
        $this->client->request('GET', sprintf('/document/%d/sign', $document->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    // ----------------------------------------------------------- sign/simple

    public function testSignSimpleWithValidPasswordCreatesSignatureAndRedirects(): void
    {
        $document = $this->createDocument(DocumentStatus::ON_SIGNING, [[$this->signer1, 1]]);

        $this->client->loginUser($this->signer1);
        $crawler = $this->client->request('GET', sprintf('/document/%d/sign', $document->getId()));
        $form = $crawler->filter(sprintf('form[action$="/document/%d/sign/simple"]', $document->getId()))
            ->form(['password' => self::PASSWORD]);
        $this->client->submit($form);

        self::assertResponseRedirects(sprintf('/view_incoming_document/%d', $document->getId()));
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-success');

        $reloaded = $this->reloadDocument($document->getId());
        self::assertSame(DocumentStatus::SIGNED, $reloaded->getStatus());
        $signature = $this->em->getRepository(DocumentSignature::class)
            ->findOneBy(['document' => $reloaded]);
        self::assertNotNull($signature);
        self::assertSame(SignatureLevel::SIMPLE, $signature->getLevel());
        self::assertSame($this->signer1->getId(), $signature->getSigner()?->getId());
    }

    public function testSignSimpleWithWrongPasswordShowsFlashError(): void
    {
        $document = $this->createDocument(DocumentStatus::ON_SIGNING, [[$this->signer1, 1]]);

        $this->client->loginUser($this->signer1);
        $crawler = $this->client->request('GET', sprintf('/document/%d/sign', $document->getId()));
        $form = $crawler->filter(sprintf('form[action$="/document/%d/sign/simple"]', $document->getId()))
            ->form(['password' => 'wrong-password']);
        $this->client->submit($form);

        self::assertResponseRedirects(sprintf('/document/%d/sign', $document->getId()));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Неверный пароль');

        self::assertNull($this->em->getRepository(DocumentSignature::class)->findOneBy(['document' => $document]));
    }

    // ------------------------------------------------------- send-to-signing

    public function testSendToSigningByAuthor(): void
    {
        $document = $this->createDocument(DocumentStatus::APPROVED, [[$this->signer1, 1]], frozen: false);

        $originalsDir = static::getContainer()->getParameter('private_upload_dir_documents_originals');
        if (!is_dir($originalsDir)) {
            mkdir($originalsDir, 0775, true);
        }
        $originalName = 'twig_sign_' . bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents($originalsDir . '/' . $originalName, '%PDF-1.4 test ' . random_bytes(16));
        $document->setOriginalFile($originalName);
        $this->em->flush();

        $this->client->loginUser($this->author);
        $crawler = $this->client->request('GET', sprintf('/view_outgoing_document/%d', $document->getId()));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter(sprintf('form[action$="/document/%d/send-to-signing"]', $document->getId()))->form();
        $this->client->submit($form);

        self::assertResponseRedirects(sprintf('/view_outgoing_document/%d', $document->getId()));

        $reloaded = $this->reloadDocument($document->getId());
        self::assertSame(DocumentStatus::ON_SIGNING, $reloaded->getStatus());
        self::assertNotNull($reloaded->getCanonicalFileHash());
        self::assertNotNull($reloaded->getVerificationCode());

        // повторно кнопка не показывается, у документа статус «На подписании»
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'отправлен на подпись');
    }

    // ------------------------------------------------------- decline-signing

    public function testDeclineRequiresReason(): void
    {
        $document = $this->createDocument(DocumentStatus::ON_SIGNING, [[$this->signer1, 1]]);

        $this->client->loginUser($this->signer1);
        $crawler = $this->client->request('GET', sprintf('/document/%d/sign', $document->getId()));
        $declineFormSelector = sprintf('form[action$="/document/%d/decline-signing"]', $document->getId());

        // без причины — flash-ошибка, статус не меняется
        $form = $crawler->filter($declineFormSelector)->form(['reason' => '   ']);
        $this->client->submit($form);
        self::assertResponseRedirects(sprintf('/view_incoming_document/%d', $document->getId()));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Укажите причину отказа');
        self::assertSame(DocumentStatus::ON_SIGNING, $this->reloadDocument($document->getId())->getStatus());

        // с причиной — документ отклоняется
        $crawler = $this->client->request('GET', sprintf('/document/%d/sign', $document->getId()));
        $form = $crawler->filter($declineFormSelector)->form(['reason' => 'не согласен с условиями']);
        $this->client->submit($form);
        self::assertResponseRedirects(sprintf('/view_incoming_document/%d', $document->getId()));

        self::assertSame(DocumentStatus::REJECTED, $this->reloadDocument($document->getId())->getStatus());
    }

    // --------------------------------------------------------- sign/enhanced

    public function testSignEnhancedFullPathWithServerGeneratedSignature(): void
    {
        $document = $this->createDocument(
            DocumentStatus::ON_SIGNING,
            [[$this->signer1, 1]],
            SignatureLevel::ENHANCED,
        );
        [$certificate, $privateKey] = $this->issueCertificateWithKey($this->signer1);

        $this->client->loginUser($this->signer1);
        $crawler = $this->client->request('GET', sprintf('/document/%d/sign', $document->getId()));
        self::assertResponseIsSuccessful();
        // ПЭП-форма отсутствует: документ уровня «усиленная подпись»
        self::assertSelectorNotExists(sprintf('form[action$="/document/%d/sign/simple"]', $document->getId()));

        $token = $crawler->filter('#enhanced-sign-form input[name="_token"]')->attr('value');
        self::assertNotEmpty($token);

        // Контракт §3.5: подписывается hex-строка хэша как ASCII-байты, RSA-SHA256
        openssl_sign((string) $document->getCanonicalFileHash(), $rawSignature, $privateKey, OPENSSL_ALGO_SHA256);

        $this->client->request('POST', sprintf('/document/%d/sign/enhanced', $document->getId()), [
            'certificateId' => $certificate->getId(),
            'signature' => base64_encode($rawSignature),
            '_token' => $token,
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
        self::assertSame(sprintf('/view_incoming_document/%d', $document->getId()), $data['redirect']);

        $reloaded = $this->reloadDocument($document->getId());
        self::assertSame(DocumentStatus::SIGNED, $reloaded->getStatus());
        $signature = $this->em->getRepository(DocumentSignature::class)->findOneBy(['document' => $reloaded]);
        self::assertNotNull($signature);
        self::assertSame(SignatureLevel::ENHANCED, $signature->getLevel());
        self::assertSame($certificate->getId(), $signature->getCertificate()?->getId());
    }

    public function testSignEnhancedRejectsForeignCertificateAndGarbageSignature(): void
    {
        $document = $this->createDocument(
            DocumentStatus::ON_SIGNING,
            [[$this->signer1, 1]],
            SignatureLevel::ENHANCED,
        );
        [$ownCertificate] = $this->issueCertificateWithKey($this->signer1);
        [$foreignCertificate] = $this->issueCertificateWithKey($this->signer2);

        $this->client->loginUser($this->signer1);
        $crawler = $this->client->request('GET', sprintf('/document/%d/sign', $document->getId()));
        $token = $crawler->filter('#enhanced-sign-form input[name="_token"]')->attr('value');

        // чужой сертификат
        $this->client->request('POST', sprintf('/document/%d/sign/enhanced', $document->getId()), [
            'certificateId' => $foreignCertificate->getId(),
            'signature' => base64_encode(random_bytes(16)),
            '_token' => $token,
        ]);
        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('certificate_not_found', $data['error']);

        // мусорная подпись собственным сертификатом
        $this->client->request('POST', sprintf('/document/%d/sign/enhanced', $document->getId()), [
            'certificateId' => $ownCertificate->getId(),
            'signature' => base64_encode(random_bytes(256)),
            '_token' => $token,
        ]);
        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('invalid_signature', $data['error']);
        self::assertStringContainsString('не прошла проверку', $data['message']);
    }

    // --------------------------------------------------------------- helpers

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
        $document->setName('twig_sign_' . bin2hex(random_bytes(6)));
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
            $canonicalName = 'twig_sign_' . bin2hex(random_bytes(8)) . '.pdf';
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
            $this->caDir = sys_get_temp_dir() . '/ca_twig_sign_test_' . bin2hex(random_bytes(6));
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
     * серверный openssl-аналог того, что делает браузер с node-forge.
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
        // Ищем в обход softdeleteable-фильтра: LoginControllerTest soft-удаляет
        // пользователей, а unique-констрейнт по login остаётся (как в CertificateAdminControllerTest).
        $filters = $this->em->getFilters();
        $softDeleteEnabled = $filters->isEnabled('softdeleteable');
        if ($softDeleteEnabled) {
            $filters->disable('softdeleteable');
        }
        $user = $this->em->getRepository(User::class)->findOneBy(['login' => $login]);
        if ($softDeleteEnabled) {
            $filters->enable('softdeleteable');
        }

        if (!$user instanceof User) {
            // lastname/firstname NOT NULL в схеме — заполняем обязательно
            $user = (new User())
                ->setLogin($login)
                ->setLastname($lastname)
                ->setFirstname('Тест');
            $this->em->persist($user);
        } elseif ($user->getDeletedAt() !== null) {
            $user->setDeletedAt(null);
        }

        $hasher = static::getContainer()->get('security.user_password_hasher');
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->flush();

        return $user;
    }

    private function createDocumentType(): DocumentType
    {
        $type = new DocumentType();
        $type->setName('twig_sign_type_' . bin2hex(random_bytes(4)));
        $this->em->persist($type);
        $this->em->flush();

        return $type;
    }

    private function createOrganization(): Organization
    {
        $organization = new Organization();
        $organization->setName('twig_sign_org_' . bin2hex(random_bytes(4)));
        $this->em->persist($organization);
        $this->em->flush();

        return $organization;
    }
}
