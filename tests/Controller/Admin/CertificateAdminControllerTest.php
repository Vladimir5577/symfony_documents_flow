<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Command\CaInitCommand;
use App\Entity\Document\UserCertificate;
use App\Entity\User\Role;
use App\Entity\User\User;
use App\Enum\Document\CertificateStatus;
use App\Enum\User\UserRole as UserRoleEnum;
use App\Service\Document\Signature\CertificateAuthorityService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CertificateAdminControllerTest extends WebTestCase
{
    private const CA_PASSPHRASE = 'test-ca-passphrase';
    private const P12_PASSWORD = 'p12-password-123';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private CertificateAuthorityService $caService;
    private User $admin;
    private User $user;
    private string $caDir;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Контейнер не пересоздаётся между запросами — подменённый CA-сервис остаётся активным
        $this->client->disableReboot();

        $container = static::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');

        // user_certificate ссылается на user с onDelete: RESTRICT — чистим сертификаты
        $this->em->createQuery('DELETE FROM App\Entity\Document\UserCertificate c')->execute();

        $this->admin = $this->findOrCreateUser('cert_test_admin', 'Админов', UserRoleEnum::ROLE_ADMIN);
        $this->user = $this->findOrCreateUser('cert_test_user', 'Пользователев', UserRoleEnum::ROLE_USER);

        // Временный корень УЦ, чтобы тест не зависел от var/ca окружения
        $this->caDir = sys_get_temp_dir() . '/ca_admin_test_' . bin2hex(random_bytes(6));
        mkdir($this->caDir, 0700, true);
        $tester = new CommandTester(new CaInitCommand(
            $this->caDir . '/root_ca.crt',
            $this->caDir . '/root_ca.key',
            self::CA_PASSPHRASE,
        ));
        self::assertSame(0, $tester->execute([]));

        $this->caService = new CertificateAuthorityService(
            $this->em,
            $this->caDir . '/root_ca.crt',
            $this->caDir . '/root_ca.key',
            self::CA_PASSPHRASE,
        );
        $container->set(CertificateAuthorityService::class, $this->caService);
    }

    protected function tearDown(): void
    {
        // Защита от падения в setUp: свойства могут быть не инициализированы
        if (isset($this->em) && $this->em->isOpen()) {
            // Чистим сертификаты, чтобы не мешать другим тестам удалять пользователей
            $this->em->createQuery('DELETE FROM App\Entity\Document\UserCertificate c')->execute();
        }

        if (isset($this->caDir)) {
            array_map('unlink', glob($this->caDir . '/*') ?: []);
            if (is_dir($this->caDir)) {
                rmdir($this->caDir);
            }
        }

        parent::tearDown();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/admin/certificates');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testNonAdminGetsForbidden(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/admin/certificates');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/certificates/issue');
        self::assertResponseStatusCodeSame(403);
    }

    public function testIssueReturnsValidP12AndPersistsCertificate(): void
    {
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', '/admin/certificates/issue');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/admin/certificates/issue"]')->form([
            'user_id' => (string) $this->user->getId(),
            'p12_password' => self::P12_PASSWORD,
        ]);
        $this->client->submit($form);

        $response = $this->client->getResponse();
        self::assertResponseIsSuccessful();
        self::assertSame('application/x-pkcs12', $response->headers->get('Content-Type'));
        self::assertStringContainsString(
            'certificate_cert_test_user.p12',
            (string) $response->headers->get('Content-Disposition'),
        );

        // .p12 открывается тем же паролем и содержит ключ + сертификат
        self::assertTrue(openssl_pkcs12_read((string) $response->getContent(), $parsed, self::P12_PASSWORD));
        self::assertArrayHasKey('pkey', $parsed);
        self::assertArrayHasKey('cert', $parsed);
        self::assertFalse(openssl_pkcs12_read((string) $response->getContent(), $failed, 'wrong-password'));

        /** @var UserCertificate|null $certificate */
        $certificate = $this->em->getRepository(UserCertificate::class)->findOneBy(['user' => $this->user]);
        self::assertNotNull($certificate);
        self::assertSame(CertificateStatus::ACTIVE, $certificate->getStatus());
        self::assertSame($this->admin->getId(), $certificate->getIssuedBy()?->getId());
        self::assertSame(
            openssl_x509_fingerprint($parsed['cert'], 'sha256'),
            openssl_x509_fingerprint((string) $certificate->getCertificatePem(), 'sha256'),
        );
    }

    public function testIssueRejectsShortPassword(): void
    {
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', '/admin/certificates/issue');
        $form = $crawler->filter('form[action$="/admin/certificates/issue"]')->form([
            'user_id' => (string) $this->user->getId(),
            'p12_password' => 'short',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/certificates/issue');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');

        self::assertNull($this->em->getRepository(UserCertificate::class)->findOneBy(['user' => $this->user]));
    }

    public function testRevokeRequiresReasonAndChangesStatus(): void
    {
        $certificate = $this->caService->issueCertificate($this->user, self::P12_PASSWORD, $this->admin)->certificate;
        $this->client->loginUser($this->admin);

        $certificateId = $certificate->getId();

        // Без причины — отказ, статус не меняется
        $crawler = $this->client->request('GET', '/admin/certificates');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter(sprintf('form[action$="/admin/certificates/%d/revoke"]', $certificateId))
            ->form(['reason' => '   ']);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/certificates');
        self::assertSame(CertificateStatus::ACTIVE, $this->reloadCertificate($certificateId)->getStatus());

        // С причиной — сертификат отзывается
        $crawler = $this->client->request('GET', '/admin/certificates');
        $form = $crawler->filter(sprintf('form[action$="/admin/certificates/%d/revoke"]', $certificateId))
            ->form(['reason' => 'Утеря носителя']);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/certificates');

        $certificate = $this->reloadCertificate($certificateId);
        self::assertSame(CertificateStatus::REVOKED, $certificate->getStatus());
        self::assertSame('Утеря носителя', $certificate->getRevocationReason());
        self::assertNotNull($certificate->getRevokedAt());
    }

    public function testReissueRevokesOldAndDownloadsNewP12(): void
    {
        $oldId = $this->caService->issueCertificate($this->user, self::P12_PASSWORD, $this->admin)->certificate->getId();
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', '/admin/certificates');
        $form = $crawler->filter(sprintf('form[action$="/admin/certificates/%d/reissue"]', $oldId))
            ->form(['p12_password' => 'new-p12-password']);
        $this->client->submit($form);

        $response = $this->client->getResponse();
        self::assertResponseIsSuccessful();
        self::assertSame('application/x-pkcs12', $response->headers->get('Content-Type'));
        self::assertTrue(openssl_pkcs12_read((string) $response->getContent(), $parsed, 'new-p12-password'));

        $old = $this->reloadCertificate($oldId);
        self::assertSame(CertificateStatus::REVOKED, $old->getStatus());
        self::assertSame('reissued', $old->getRevocationReason());

        /** @var UserCertificate[] $active */
        $active = $this->em->getRepository(UserCertificate::class)
            ->findBy(['user' => $this->user, 'status' => CertificateStatus::ACTIVE]);
        self::assertCount(1, $active);
        self::assertNotSame($oldId, $active[0]->getId());
    }

    /** Перечитывает сертификат из БД, минуя возможное устаревшее состояние Unit of Work. */
    private function reloadCertificate(int $id): UserCertificate
    {
        $this->em->clear();
        $certificate = $this->em->find(UserCertificate::class, $id);
        self::assertInstanceOf(UserCertificate::class, $certificate);

        return $certificate;
    }

    private function findOrCreateUser(string $login, string $lastname, UserRoleEnum $roleName): User
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
            if ($user->getDeletedAt() !== null) {
                $user->setDeletedAt(null);
                $this->em->flush();
            }

            return $user;
        }

        $role = $this->em->getRepository(Role::class)->findOneBy(['name' => $roleName->value]);
        if ($role === null) {
            $role = new Role($roleName);
            $this->em->persist($role);
        }

        // lastname/firstname NOT NULL в схеме — заполняем обязательно
        $user = (new User())
            ->setLogin($login)
            ->setLastname($lastname)
            ->setFirstname('Тест')
            ->setPassword('not-a-real-hash');
        $user->addRoleEntity($role);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
