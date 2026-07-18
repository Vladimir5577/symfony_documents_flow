<?php

declare(strict_types=1);

namespace App\Tests\Controller\SpaApi\DocumentsFlow;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentType;
use App\Entity\Document\DocumentUserRecipient;
use App\Entity\Organization\Organization;
use App\Entity\User\User;
use App\Enum\Document\DocumentRecipientRole;
use App\Enum\Document\DocumentStatus;
use App\Enum\Document\SignatureLevel;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Функциональные тесты Фазы 5 (T5.2, SPA):
 * полный флоу подпись → SIGNED → печатная форма; verify-file; signed-form.
 */
final class DocumentSignatureVerifyTest extends WebTestCase
{
    private const PASSWORD = 'verify-test-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $author;
    private User $signer;
    private DocumentType $documentType;
    private Organization $organization;
    private string $canonicalDir;
    private string $signedFormsDir;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->canonicalDir = $container->getParameter('private_upload_dir_documents_canonical');
        $this->signedFormsDir = $container->getParameter('private_upload_dir_documents_signed_forms');

        $this->author = $this->findOrCreateUser('sig_vrf_author', 'Авторов');
        $this->signer = $this->findOrCreateUser('sig_vrf_signer', 'Подписантов');

        $this->documentType = $this->em->getRepository(DocumentType::class)->findOneBy([])
            ?? $this->createDocumentType();
        $this->organization = $this->em->getRepository(Organization::class)->findOneBy([])
            ?? $this->createOrganization();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();

            // печатные формы удаляем по данным документов до удаления самих документов
            $signedForms = $this->em->createQuery(
                'SELECT d.signedFormFile FROM App\Entity\Document\Document d WHERE d.name LIKE :n AND d.signedFormFile IS NOT NULL',
            )->setParameter('n', 'sig_vrf_%')->getSingleColumnResult();
            foreach ($signedForms as $file) {
                @unlink($this->signedFormsDir . '/' . basename((string) $file));
            }

            foreach ([
                'DELETE FROM App\Entity\Document\DocumentSignature s WHERE s.document IN (SELECT d FROM App\Entity\Document\Document d WHERE d.name LIKE :n)',
                'DELETE FROM App\Entity\Document\DocumentHistory h WHERE h.document IN (SELECT d2 FROM App\Entity\Document\Document d2 WHERE d2.name LIKE :n)',
                'DELETE FROM App\Entity\Document\DocumentUserRecipient r WHERE r.document IN (SELECT d3 FROM App\Entity\Document\Document d3 WHERE d3.name LIKE :n)',
                'DELETE FROM App\Entity\Document\Document d4 WHERE d4.name LIKE :n',
            ] as $dql) {
                $this->em->createQuery($dql)->setParameter('n', 'sig_vrf_%')->execute();
            }
        }

        if (isset($this->canonicalDir)) {
            array_map('unlink', glob($this->canonicalDir . '/sig_vrf_*') ?: []);
        }

        parent::tearDown();
    }

    // ------------------------------------------------- полный флоу → SIGNED

    public function testSigningTransitionGeneratesSignedForm(): void
    {
        $document = $this->createDocument(DocumentStatus::ON_SIGNING);

        $data = $this->jsonRequest($this->signer, 'POST', sprintf('/documents/%d/sign/simple', $document->getId()), [
            'password' => self::PASSWORD,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(DocumentStatus::SIGNED->value, $data['document']['status']);

        $reloaded = $this->reloadDocument($document->getId());
        self::assertNotNull($reloaded->getSignedFormFile(), 'Печатная форма генерируется при переходе в SIGNED.');
        $path = $this->signedFormsDir . '/' . $reloaded->getSignedFormFile();
        self::assertFileExists($path);
        self::assertStringStartsWith('%PDF', (string) file_get_contents($path));
        self::assertStringContainsString(
            '/verify/' . $reloaded->getVerificationCode(),
            (string) file_get_contents($path),
        );
    }

    // ------------------------------------------------------------ verify-file

    public function testVerifyFileFindsSignedDocumentByContent(): void
    {
        $document = $this->createDocument(DocumentStatus::ON_SIGNING);
        $this->jsonRequest($this->signer, 'POST', sprintf('/documents/%d/sign/simple', $document->getId()), [
            'password' => self::PASSWORD,
        ]);
        self::assertResponseIsSuccessful();

        $data = $this->uploadVerifyFile($this->canonicalDir . '/' . $document->getCanonicalFile());

        self::assertResponseIsSuccessful();
        self::assertTrue($data['found']);
        self::assertSame($document->getId(), $data['document']['id']);
        self::assertSame(DocumentStatus::SIGNED->value, $data['document']['status']);
        self::assertCount(1, $data['signatures']);
        self::assertTrue($data['signatures'][0]['valid']);
        self::assertSame(SignatureLevel::SIMPLE->value, $data['signatures'][0]['level']);
    }

    public function testVerifyFileModifiedContentNotFound(): void
    {
        $document = $this->createDocument(DocumentStatus::ON_SIGNING);

        $modified = tempnam(sys_get_temp_dir(), 'sig_vrf');
        $content = (string) file_get_contents($this->canonicalDir . '/' . $document->getCanonicalFile());
        $content[strlen($content) - 1] = $content[strlen($content) - 1] === 'A' ? 'B' : 'A'; // изменён один байт
        file_put_contents($modified, $content);

        $data = $this->uploadVerifyFile($modified);
        unlink($modified);

        self::assertResponseIsSuccessful();
        self::assertFalse($data['found']);
        self::assertArrayNotHasKey('document', $data);
    }

    public function testVerifyFileRejectsNonPdf(): void
    {
        $txt = tempnam(sys_get_temp_dir(), 'sig_vrf');
        file_put_contents($txt, 'просто текст, не PDF');

        $data = $this->uploadVerifyFile($txt, 'file.txt', 'text/plain');
        unlink($txt);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(SpaApiError::FILE_INVALID_TYPE, $data['error']);
    }

    public function testVerifyFileRejectsTooLargeFile(): void
    {
        $big = tempnam(sys_get_temp_dir(), 'sig_vrf');
        $handle = fopen($big, 'wb');
        fwrite($handle, '%PDF-1.4 ');
        fseek($handle, 9 * 1024 * 1024 + 1);
        fwrite($handle, 'x');
        fclose($handle);

        $data = $this->uploadVerifyFile($big);
        unlink($big);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(SpaApiError::FILE_TOO_LARGE, $data['error']);
    }

    public function testVerifyFileWithoutFile(): void
    {
        $data = $this->jsonRequest($this->author, 'POST', '/verify-file');

        self::assertResponseStatusCodeSame(400);
        self::assertSame(SpaApiError::FILE_NOT_PROVIDED, $data['error']);
    }

    // ------------------------------------------------------------ signed-form

    public function testSignedFormDownloadAndLazyRegeneration(): void
    {
        $document = $this->createDocument(DocumentStatus::ON_SIGNING);
        $this->jsonRequest($this->signer, 'POST', sprintf('/documents/%d/sign/simple', $document->getId()), [
            'password' => self::PASSWORD,
        ]);
        self::assertResponseIsSuccessful();

        // обычная отдача
        $this->rawRequest($this->author, 'GET', sprintf('/documents/%d/signed-form', $document->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $this->client->getInternalResponse()->getContent());

        // ленивая генерация: файл «потеряли», ссылку в БД обнулили
        $reloaded = $this->reloadDocument($document->getId());
        @unlink($this->signedFormsDir . '/' . $reloaded->getSignedFormFile());
        $reloaded->setSignedFormFile(null);
        $this->em->flush();

        $this->rawRequest($this->author, 'GET', sprintf('/documents/%d/signed-form', $document->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('%PDF', (string) $this->client->getInternalResponse()->getContent());
        self::assertNotNull($this->reloadDocument($document->getId())->getSignedFormFile());
    }

    public function testSignedFormNotReadyForUnsignedDocument(): void
    {
        $document = $this->createDocument(DocumentStatus::ON_SIGNING);

        $data = $this->jsonRequest($this->author, 'GET', sprintf('/documents/%d/signed-form', $document->getId()));

        self::assertResponseStatusCodeSame(400);
        self::assertSame(SpaApiError::SIGNED_FORM_NOT_READY, $data['error']);
    }

    // --------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function uploadVerifyFile(string $path, string $name = 'document.pdf', string $mime = 'application/pdf'): array
    {
        // копия: UploadedFile в тестовом режиме перемещает/удаляет исходник после запроса
        $copy = tempnam(sys_get_temp_dir(), 'sig_vrf_up');
        copy($path, $copy);

        /** @var JWTTokenManagerInterface $jwtManager */
        $jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');

        $this->client->request(
            'POST',
            '/spa/api/documents-flow/verify-file',
            [],
            ['file' => new UploadedFile($copy, $name, $mime, null, true)],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwtManager->create($this->author)],
        );

        @unlink($copy);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonRequest(User $user, string $method, string $path, ?array $payload = null): array
    {
        $this->rawRequest($user, $method, $path, $payload);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function rawRequest(User $user, string $method, string $path, ?array $payload = null): void
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
    }

    /**
     * Документ с одним подписантом и настоящим PDF-каноническим файлом
     * (печатная форма собирается FPDI — фиктивный контент не подойдёт).
     */
    private function createDocument(DocumentStatus $status): Document
    {
        $now = new \DateTimeImmutable();

        $document = new Document();
        $document->setName('sig_vrf_' . bin2hex(random_bytes(6)));
        $document->setDocumentType($this->documentType);
        $document->setOrganizationCreator($this->organization);
        $document->setCreatedBy($this->author);
        $document->setStatus($status);
        $document->setIsPublished(true);
        $document->setSignatureLevel(SignatureLevel::SIMPLE);

        $recipient = new DocumentUserRecipient();
        $recipient->setDocument($document);
        $recipient->setUser($this->signer);
        $recipient->setRole(DocumentRecipientRole::SIGNER);
        $recipient->setStatus(DocumentStatus::NEW);
        $recipient->setSigningOrder(1);
        $recipient->setCreatedAt($now);
        $recipient->setUpdatedAt($now);
        $document->addUserRecipient($recipient);

        if (!is_dir($this->canonicalDir)) {
            mkdir($this->canonicalDir, 0775, true);
        }
        $canonicalName = 'sig_vrf_' . bin2hex(random_bytes(8)) . '.pdf';
        // настоящий PDF + уникальный хвост в комментарии, чтобы хэш был уникален
        $content = (string) file_get_contents(__DIR__ . '/../../../Service/Signature/Fixtures/sample.pdf');
        $content .= "\n%unique " . bin2hex(random_bytes(16)) . "\n";
        file_put_contents($this->canonicalDir . '/' . $canonicalName, $content);
        $document->setCanonicalFile($canonicalName);
        $document->setCanonicalFileHash(hash('sha256', $content));
        $document->setVerificationCode(substr(bin2hex(random_bytes(8)), 0, 16));

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
        $type->setName('sig_vrf_type_' . bin2hex(random_bytes(4)));
        $this->em->persist($type);
        $this->em->flush();

        return $type;
    }

    private function createOrganization(): Organization
    {
        $organization = new Organization();
        $organization->setName('sig_vrf_org_' . bin2hex(random_bytes(4)));
        $this->em->persist($organization);
        $this->em->flush();

        return $organization;
    }
}
