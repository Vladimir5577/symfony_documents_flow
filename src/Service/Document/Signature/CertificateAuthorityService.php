<?php

declare(strict_types=1);

namespace App\Service\Document\Signature;

use App\DTO\Document\Signature\IssuedCertificateResult;
use App\Entity\Document\UserCertificate;
use App\Entity\User\User;
use App\Enum\Document\CertificateStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Внутренний удостоверяющий центр (УНЭП).
 *
 * Приватный ключ пользователя существует ТОЛЬКО в памяти запроса:
 * он генерируется, упаковывается в .p12 и не сохраняется/не логируется нигде.
 * В БД остаётся только сертификат (PEM) и его метаданные.
 */
final class CertificateAuthorityService
{
    private const CERT_DAYS = 365;

    private const OPENSSL_CONFIG = <<<CNF
        [req]
        distinguished_name = req_dn
        string_mask = utf8only
        [req_dn]
        [v3_usr]
        basicConstraints = critical, CA:FALSE
        keyUsage = critical, digitalSignature
        subjectKeyIdentifier = hash
        CNF;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $caRootCertPath,
        private readonly string $caRootKeyPath,
        private readonly string $caRootKeyPassphrase,
    ) {
    }

    public function issueCertificate(User $user, string $p12Password, User $issuedBy): IssuedCertificateResult
    {
        if (!is_file($this->caRootCertPath) || !is_file($this->caRootKeyPath)) {
            throw new \RuntimeException('Корневая пара УЦ не найдена. Выполните команду app:ca:init.');
        }

        $caCertPem = (string) file_get_contents($this->caRootCertPath);
        $caKey = openssl_pkey_get_private((string) file_get_contents($this->caRootKeyPath), $this->caRootKeyPassphrase);
        if ($caKey === false) {
            throw new \RuntimeException('Не удалось открыть корневой ключ УЦ: ' . (string) openssl_error_string());
        }

        $configPath = tempnam(sys_get_temp_dir(), 'ca_cnf_');
        if ($configPath === false) {
            throw new \RuntimeException('Не удалось создать временный openssl-конфиг.');
        }
        file_put_contents($configPath, self::OPENSSL_CONFIG);

        try {
            // Пара ключей пользователя — только в памяти запроса
            $userKey = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'config' => $configPath,
            ]);
            if ($userKey === false) {
                throw new \RuntimeException('Не удалось сгенерировать ключевую пару: ' . (string) openssl_error_string());
            }

            $dn = array_filter([
                'commonName' => $user->getLogin(),
                'surname' => $user->getLastname(),
                'givenName' => trim(($user->getFirstname() ?? '') . ' ' . ($user->getPatronymic() ?? '')),
            ], static fn (?string $v): bool => $v !== null && $v !== '');

            $csr = openssl_csr_new($dn, $userKey, ['digest_alg' => 'sha256', 'config' => $configPath]);
            if ($csr === false) {
                throw new \RuntimeException('Не удалось создать CSR: ' . (string) openssl_error_string());
            }

            $x509 = openssl_csr_sign(
                $csr,
                $caCertPem,
                $caKey,
                self::CERT_DAYS,
                ['digest_alg' => 'sha256', 'x509_extensions' => 'v3_usr', 'config' => $configPath],
                random_int(1, PHP_INT_MAX), // уникальный серийник
            );
            if ($x509 === false) {
                throw new \RuntimeException('Не удалось подписать сертификат корневым ключом: ' . (string) openssl_error_string());
            }
        } finally {
            @unlink($configPath);
        }

        if (!openssl_x509_export($x509, $certPem)) {
            throw new \RuntimeException('Не удалось экспортировать сертификат.');
        }

        if (!openssl_pkcs12_export($x509, $p12Binary, $userKey, $p12Password)) {
            throw new \RuntimeException('Не удалось собрать контейнер .p12: ' . (string) openssl_error_string());
        }

        $info = openssl_x509_parse($x509);
        if ($info === false) {
            throw new \RuntimeException('Не удалось разобрать выпущенный сертификат.');
        }

        $certificate = (new UserCertificate())
            ->setUser($user)
            ->setSerialNumber(strtoupper((string) $info['serialNumberHex']))
            ->setSubjectDn((string) $info['name'])
            ->setCertificatePem($certPem)
            ->setValidFrom((new \DateTimeImmutable())->setTimestamp((int) $info['validFrom_time_t']))
            ->setValidTo((new \DateTimeImmutable())->setTimestamp((int) $info['validTo_time_t']))
            ->setStatus(CertificateStatus::ACTIVE)
            ->setIssuedBy($issuedBy);

        $this->em->persist($certificate);
        $this->em->flush();

        return new IssuedCertificateResult($p12Binary, $certificate);
    }

    public function revoke(UserCertificate $cert, string $reason, User $admin): void
    {
        $cert->setStatus(CertificateStatus::REVOKED)
            ->setRevokedAt(new \DateTimeImmutable())
            ->setRevocationReason($reason);

        $this->em->flush();
    }
}
