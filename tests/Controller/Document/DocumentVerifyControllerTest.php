<?php

declare(strict_types=1);

namespace App\Tests\Controller\Document;

use App\Entity\Document\Document;
use App\Entity\Document\DocumentSignature;
use App\Entity\Document\DocumentType;
use App\Entity\Organization\Organization;
use App\Entity\User\User;
use App\Enum\Document\DocumentStatus;
use App\Enum\Document\SignatureLevel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Функциональные тесты Twig-страниц проверки подписи (Фаза 5, T5.2):
 * /verify, /verify/{code}, скачивание печатной формы.
 */
final class DocumentVerifyControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $author;
    private User $outsider;
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

        $this->author = $this->findOrCreateUser('vrf_page_author', 'Авторов');
        $this->outsider = $this->findOrCreateUser('vrf_page_outsider', 'Посторонний');
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();

            $signedForms = $this->em->createQuery(
                'SELECT d.signedFormFile FROM App\Entity\Document\Document d WHERE d.name LIKE :n AND d.signedFormFile IS NOT NULL',
            )->setParameter('n', 'vrf_page_%')->getSingleColumnResult();
            foreach ($signedForms as $file) {
                @unlink($this->signedFormsDir . '/' . basename((string) $file));
            }

            foreach ([
                'DELETE FROM App\Entity\Document\DocumentSignature s WHERE s.document IN (SELECT d FROM App\Entity\Document\Document d WHERE d.name LIKE :n)',
                'DELETE FROM App\Entity\Document\DocumentHistory h WHERE h.document IN (SELECT d2 FROM App\Entity\Document\Document d2 WHERE d2.name LIKE :n)',
                'DELETE FROM App\Entity\Document\DocumentUserRecipient r WHERE r.document IN (SELECT d3 FROM App\Entity\Document\Document d3 WHERE d3.name LIKE :n)',
                'DELETE FROM App\Entity\Document\Document d4 WHERE d4.name LIKE :n',
            ] as $dql) {
                $this->em->createQuery($dql)->setParameter('n', 'vrf_page_%')->execute();
            }
        }

        if (isset($this->canonicalDir)) {
            array_map('unlink', glob($this->canonicalDir . '/vrf_page_*') ?: []);
        }

        parent::tearDown();
    }

    public function testVerifyPageRequiresAuthentication(): void
    {
        $this->client->request('GET', '/verify');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testVerifyPageUploadFindsDocument(): void
    {
        $document = $this->createSignedDocument();
        $this->client->loginUser($this->author);

        $crawler = $this->client->request('GET', '/verify');
        self::assertResponseIsSuccessful();

        $upload = tempnam(sys_get_temp_dir(), 'vrf_page_up');
        copy($this->canonicalDir . '/' . $document->getCanonicalFile(), $upload);

        $form = $crawler->selectButton('Проверить')->form();
        $form['file']->upload($upload);
        $this->client->submit($form);
        @unlink($upload);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Документ найден', $html);
        self::assertStringContainsString((string) $document->getName(), $html);
        self::assertStringContainsString('Подпись действительна', $html);
        self::assertStringContainsString('Все подписи документа действительны', $html);
    }

    public function testVerifyPageUploadModifiedFileNotFound(): void
    {
        $document = $this->createSignedDocument();
        $this->client->loginUser($this->author);

        $upload = tempnam(sys_get_temp_dir(), 'vrf_page_up');
        file_put_contents(
            $upload,
            file_get_contents($this->canonicalDir . '/' . $document->getCanonicalFile()) . 'x',
        );

        $crawler = $this->client->request('GET', '/verify');
        $form = $crawler->selectButton('Проверить')->form();
        $form['file']->upload($upload);
        $this->client->submit($form);
        @unlink($upload);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Документ не найден',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testVerifyByCodeShowsSignatures(): void
    {
        $document = $this->createSignedDocument();

        // страница по QR доступна любому авторизованному, даже без доступа к документу
        $this->client->loginUser($this->outsider);
        $this->client->request('GET', '/verify/' . $document->getVerificationCode());

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString((string) $document->getName(), $html);
        self::assertStringContainsString('Авторов', $html); // подписант
        self::assertStringContainsString('Подпись действительна', $html);
        // кнопки скачивания нет — нет доступа к документу
        self::assertStringNotContainsString('Скачать печатную форму', $html);

        // у автора кнопка есть
        $this->client->loginUser($this->author);
        $this->client->request('GET', '/verify/' . $document->getVerificationCode());
        self::assertStringContainsString(
            'Скачать печатную форму',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testVerifyByUnknownCode404(): void
    {
        $this->client->loginUser($this->author);
        $this->client->request('GET', '/verify/00000000deadbeef');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSignedFormDownloadRespectsDocumentAccess(): void
    {
        $document = $this->createSignedDocument();
        $url = sprintf('/verify/%s/signed-form', $document->getVerificationCode());

        // посторонний — 403
        $this->client->loginUser($this->outsider);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        // автор — PDF (лениво генерируется: signedFormFile ещё пуст)
        $this->client->loginUser($this->author);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $this->client->getInternalResponse()->getContent());
    }

    // --------------------------------------------------------------- helpers

    /**
     * Документ в статусе SIGNED с настоящим PDF-каноническим файлом
     * и записью подписи автора (ПЭП).
     */
    private function createSignedDocument(): Document
    {
        $documentType = $this->em->getRepository(DocumentType::class)->findOneBy([])
            ?? $this->createDocumentType();
        $organization = $this->em->getRepository(Organization::class)->findOneBy([])
            ?? $this->createOrganization();

        if (!is_dir($this->canonicalDir)) {
            mkdir($this->canonicalDir, 0775, true);
        }
        $canonicalName = 'vrf_page_' . bin2hex(random_bytes(8)) . '.pdf';
        $content = (string) file_get_contents(__DIR__ . '/../../Service/Signature/Fixtures/sample.pdf');
        $content .= "\n%unique " . bin2hex(random_bytes(16)) . "\n";
        file_put_contents($this->canonicalDir . '/' . $canonicalName, $content);

        $document = new Document();
        $document->setName('vrf_page_' . bin2hex(random_bytes(6)));
        $document->setDocumentType($documentType);
        $document->setOrganizationCreator($organization);
        $document->setCreatedBy($this->author);
        $document->setStatus(DocumentStatus::SIGNED);
        $document->setIsPublished(true);
        $document->setSignatureLevel(SignatureLevel::SIMPLE);
        $document->setCanonicalFile($canonicalName);
        $document->setCanonicalFileHash(hash('sha256', $content));
        $document->setVerificationCode(substr(bin2hex(random_bytes(8)), 0, 16));

        $signature = (new DocumentSignature())
            ->setDocument($document)
            ->setSigner($this->author)
            ->setLevel(SignatureLevel::SIMPLE)
            ->setAlgorithm('password-confirmation')
            ->setDocumentHash((string) $document->getCanonicalFileHash())
            ->setSignedAt(new \DateTimeImmutable());
        $document->addSignature($signature);

        $this->em->persist($document);
        $this->em->persist($signature);
        $this->em->flush();

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

        if ($user instanceof User) {
            $user->setDeletedAt(null);
            $this->em->flush();
        }
        if (!$user instanceof User) {
            $user = (new User())
                ->setLogin($login)
                ->setLastname($lastname)
                ->setFirstname('Тест');
            $hasher = static::getContainer()->get('security.user_password_hasher');
            $user->setPassword($hasher->hashPassword($user, 'vrf-page-password'));
            $this->em->persist($user);
            $this->em->flush();
        }

        return $user;
    }

    private function createDocumentType(): DocumentType
    {
        $type = new DocumentType();
        $type->setName('vrf_page_type_' . bin2hex(random_bytes(4)));
        $this->em->persist($type);
        $this->em->flush();

        return $type;
    }

    private function createOrganization(): Organization
    {
        $organization = new Organization();
        $organization->setName('vrf_page_org_' . bin2hex(random_bytes(4)));
        $this->em->persist($organization);
        $this->em->flush();

        return $organization;
    }
}
