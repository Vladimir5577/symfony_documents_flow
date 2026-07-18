<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SignatureMetricsCommand;
use App\Entity\Document\UserCertificate;
use App\Entity\User\Role;
use App\Entity\User\User;
use App\Enum\Document\CertificateStatus;
use App\Enum\User\UserRole as UserRoleEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SignatureMetricsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ?UserCertificate $certificate = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
    }

    protected function tearDown(): void
    {
        if ($this->certificate !== null && $this->em->isOpen()) {
            $this->em->remove($this->certificate);
            $this->em->flush();
        }

        parent::tearDown();
    }

    public function testCountersMatchDatabase(): void
    {
        /** @var SignatureMetricsCommand $command */
        $command = static::getContainer()->get(SignatureMetricsCommand::class);

        $before = $command->collect();

        // фикстура: активный сертификат, истекающий через 10 дней → попадает в expiring_30d
        $now = new \DateTimeImmutable();
        $this->certificate = (new UserCertificate())
            ->setUser($this->findOrCreateUser('metrics_test_user'))
            ->setSerialNumber('metrics-test-' . bin2hex(random_bytes(8)))
            ->setSubjectDn('CN=Metrics Test')
            ->setCertificatePem('-----BEGIN CERTIFICATE-----metrics-test-----END CERTIFICATE-----')
            ->setValidFrom($now->modify('-1 day'))
            ->setValidTo($now->modify('+10 days'))
            ->setStatus(CertificateStatus::ACTIVE)
            ->setCreatedAt($now);
        $this->em->persist($this->certificate);
        $this->em->flush();

        $after = $command->collect();

        // сертификатные счётчики отражают вставку в БД
        self::assertSame($before['certificates_active'] + 1, $after['certificates_active']);
        self::assertSame($before['certificates_expiring_30d'] + 1, $after['certificates_expiring_30d']);
        self::assertSame($before['certificates_revoked'], $after['certificates_revoked']);

        // счётчики подписаний совпадают с прямыми запросами к БД
        $connection = $this->em->getConnection();
        self::assertSame(
            (int) $connection->fetchOne("SELECT COUNT(*) FROM document_signature WHERE level = 'simple'"),
            $after['signatures_total']['simple'],
        );
        self::assertSame(
            (int) $connection->fetchOne("SELECT COUNT(*) FROM document_signature WHERE level = 'enhanced'"),
            $after['signatures_total']['enhanced'],
        );
        self::assertSame(
            (int) $connection->fetchOne("SELECT COUNT(*) FROM document_history WHERE action LIKE 'signing_declined%'"),
            $after['declines_total'],
        );

        // команда выводит валидный JSON с теми же ключами
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute([]));
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        foreach (['generated_at', 'signatures_total', 'declines_total', 'documents_on_signing', 'documents_signed', 'certificates_active', 'certificates_revoked', 'certificates_expiring_30d'] as $key) {
            self::assertArrayHasKey($key, $decoded);
        }
    }

    private function findOrCreateUser(string $login): User
    {
        // в обход softdeleteable-фильтра: LoginControllerTest soft-удаляет пользователей
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
            return $user;
        }

        $role = $this->em->getRepository(Role::class)->findOneBy(['name' => UserRoleEnum::ROLE_USER->value])
            ?? new Role(UserRoleEnum::ROLE_USER);
        if ($role->getId() === null) {
            $this->em->persist($role);
        }

        $user = (new User())
            ->setLogin($login)
            ->setLastname('Метриков')
            ->setFirstname('Тест')
            ->setPassword('not-a-real-hash');
        $user->addRoleEntity($role);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
