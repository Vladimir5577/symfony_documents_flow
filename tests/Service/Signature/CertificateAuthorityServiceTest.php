<?php

declare(strict_types=1);

namespace App\Tests\Service\Signature;

use App\Command\CaInitCommand;
use App\Entity\Document\UserCertificate;
use App\Entity\User\User;
use App\Enum\Document\CertificateStatus;
use App\Service\Document\Signature\CertificateAuthorityService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CertificateAuthorityServiceTest extends TestCase
{
    private const PASSPHRASE = 'test-ca-passphrase';
    private const P12_PASSWORD = 'user-p12-password';

    private string $caDir;

    protected function setUp(): void
    {
        $this->caDir = sys_get_temp_dir() . '/ca_test_' . bin2hex(random_bytes(6));
        mkdir($this->caDir, 0700, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->caDir . '/*') ?: []);
        if (is_dir($this->caDir)) {
            rmdir($this->caDir);
        }
    }

    public function testCaInitCreatesRootPairWithRestrictedKeyPermissions(): void
    {
        $tester = $this->runCaInit();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->certPath());
        self::assertFileExists($this->keyPath());
        self::assertSame(0o600, fileperms($this->keyPath()) & 0o777, 'Файл корневого ключа должен иметь права 0600');

        // ключ зашифрован passphrase: без него не открывается, с ним открывается
        $keyPem = (string) file_get_contents($this->keyPath());
        self::assertFalse(openssl_pkey_get_private($keyPem, 'wrong-passphrase'));
        self::assertNotFalse(openssl_pkey_get_private($keyPem, self::PASSPHRASE));
    }

    public function testCaInitRefusesToOverwriteExistingPair(): void
    {
        self::assertSame(Command::SUCCESS, $this->runCaInit()->getStatusCode());
        $certBefore = (string) file_get_contents($this->certPath());
        $keyBefore = (string) file_get_contents($this->keyPath());

        $second = $this->runCaInit();

        self::assertSame(Command::FAILURE, $second->getStatusCode());
        self::assertStringContainsString('уже существует', $second->getDisplay());
        self::assertSame($certBefore, (string) file_get_contents($this->certPath()), 'Сертификат не должен быть перезаписан');
        self::assertSame($keyBefore, (string) file_get_contents($this->keyPath()), 'Ключ не должен быть перезаписан');
    }

    public function testIssuedCertificateVerifiesAgainstRoot(): void
    {
        $this->runCaInit();

        $admin = $this->makeAdmin();
        $result = $this->makeService()->issueCertificate($this->makeUser(), self::P12_PASSWORD, $admin);
        $certificate = $result->certificate;

        $rootPem = (string) file_get_contents($this->certPath());
        self::assertSame(
            1,
            openssl_x509_verify((string) $certificate->getCertificatePem(), $rootPem),
            'Выпущенный сертификат должен верифицироваться против корневого',
        );

        self::assertNotSame('', (string) $certificate->getSerialNumber());
        self::assertStringContainsString('CN=ivanov', (string) $certificate->getSubjectDn());
        self::assertSame(CertificateStatus::ACTIVE, $certificate->getStatus());
        self::assertSame($admin, $certificate->getIssuedBy());

        // срок действия ~365 дней
        $days = $certificate->getValidFrom()->diff($certificate->getValidTo())->days;
        self::assertSame(365, $days);
    }

    public function testP12OpensWithSamePasswordAndContainsKeyAndCertificate(): void
    {
        $this->runCaInit();

        $result = $this->makeService()->issueCertificate($this->makeUser(), self::P12_PASSWORD, $this->makeAdmin());

        self::assertFalse(openssl_pkcs12_read($result->p12Binary, $failed, 'wrong-password'));

        self::assertTrue(openssl_pkcs12_read($result->p12Binary, $parsed, self::P12_PASSWORD));
        self::assertArrayHasKey('pkey', $parsed);
        self::assertArrayHasKey('cert', $parsed);

        // сертификат в .p12 — тот же, что сохранён в сущности
        self::assertSame(
            openssl_x509_fingerprint($parsed['cert'], 'sha256'),
            openssl_x509_fingerprint((string) $result->certificate->getCertificatePem(), 'sha256'),
        );

        // ключ из .p12 соответствует сертификату
        self::assertTrue(openssl_x509_check_private_key($parsed['cert'], $parsed['pkey']));
    }

    public function testTwoIssuedCertificatesHaveUniqueSerials(): void
    {
        $this->runCaInit();
        $service = $this->makeService();

        $first = $service->issueCertificate($this->makeUser(), self::P12_PASSWORD, $this->makeAdmin());
        $second = $service->issueCertificate($this->makeUser(), self::P12_PASSWORD, $this->makeAdmin());

        self::assertNotSame($first->certificate->getSerialNumber(), $second->certificate->getSerialNumber());
    }

    public function testIssueFailsWithoutInitializedCa(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('app:ca:init');

        $this->makeService()->issueCertificate($this->makeUser(), self::P12_PASSWORD, $this->makeAdmin());
    }

    public function testRevokeSetsStatusReasonAndDate(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $service = new CertificateAuthorityService($em, $this->certPath(), $this->keyPath(), self::PASSPHRASE);

        $certificate = (new UserCertificate())->setStatus(CertificateStatus::ACTIVE);
        $admin = new User();
        $admin->setLogin('admin');

        $service->revoke($certificate, 'Утеря носителя', $admin);

        self::assertSame(CertificateStatus::REVOKED, $certificate->getStatus());
        self::assertSame('Утеря носителя', $certificate->getRevocationReason());
        self::assertNotNull($certificate->getRevokedAt());
    }

    private function certPath(): string
    {
        return $this->caDir . '/root_ca.crt';
    }

    private function keyPath(): string
    {
        return $this->caDir . '/root_ca.key';
    }

    private function runCaInit(): CommandTester
    {
        $tester = new CommandTester(new CaInitCommand($this->certPath(), $this->keyPath(), self::PASSPHRASE));
        $tester->execute([]);

        return $tester;
    }

    private function makeService(): CertificateAuthorityService
    {
        return new CertificateAuthorityService(
            $this->createStub(EntityManagerInterface::class),
            $this->certPath(),
            $this->keyPath(),
            self::PASSPHRASE,
        );
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->setLogin('ivanov');
        $user->setLastname('Иванов');
        $user->setFirstname('Иван');
        $user->setPatronymic('Иванович');

        return $user;
    }

    private function makeAdmin(): User
    {
        $admin = new User();
        $admin->setLogin('admin');

        return $admin;
    }
}
