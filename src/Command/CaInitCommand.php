<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ca:init',
    description: 'Генерирует корневую пару внутреннего УЦ (RSA-4096, самоподписанный сертификат на 10 лет). Существующие файлы не перезаписывает.',
)]
class CaInitCommand extends Command
{
    private const CA_DAYS = 3650; // 10 лет

    private const OPENSSL_CONFIG = <<<CNF
        [req]
        distinguished_name = req_dn
        string_mask = utf8only
        [req_dn]
        [v3_ca]
        basicConstraints = critical, CA:TRUE
        keyUsage = critical, keyCertSign, cRLSign
        subjectKeyIdentifier = hash
        CNF;

    public function __construct(
        private readonly string $caRootCertPath,
        private readonly string $caRootKeyPath,
        private readonly string $caRootKeyPassphrase,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (file_exists($this->caRootCertPath) || file_exists($this->caRootKeyPath)) {
            $io->error(sprintf(
                'Корневая пара УЦ уже существует (%s / %s). Перезапись запрещена: удалите файлы вручную, если действительно требуется ротация.',
                $this->caRootCertPath,
                $this->caRootKeyPath,
            ));

            return Command::FAILURE;
        }

        if ($this->caRootKeyPassphrase === '') {
            $io->error('Не задан CA_ROOT_KEY_PASSPHRASE в окружении.');

            return Command::FAILURE;
        }

        foreach ([\dirname($this->caRootCertPath), \dirname($this->caRootKeyPath)] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                $io->error(sprintf('Не удалось создать директорию %s.', $dir));

                return Command::FAILURE;
            }
        }

        $configPath = tempnam(sys_get_temp_dir(), 'ca_cnf_');
        if ($configPath === false) {
            $io->error('Не удалось создать временный openssl-конфиг.');

            return Command::FAILURE;
        }
        file_put_contents($configPath, self::OPENSSL_CONFIG);

        try {
            $key = openssl_pkey_new([
                'private_key_bits' => 4096,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'config' => $configPath,
            ]);
            if ($key === false) {
                $io->error('Не удалось сгенерировать корневой ключ: ' . (string) openssl_error_string());

                return Command::FAILURE;
            }

            $dn = [
                'commonName' => 'Don Stroy Mash Internal Root CA',
                'organizationName' => 'Don Stroy Mash',
            ];

            $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256', 'config' => $configPath]);
            $x509 = $csr === false ? false : openssl_csr_sign(
                $csr,
                null, // самоподписанный
                $key,
                self::CA_DAYS,
                ['digest_alg' => 'sha256', 'x509_extensions' => 'v3_ca', 'config' => $configPath],
                random_int(1, PHP_INT_MAX),
            );
            if ($x509 === false || !openssl_x509_export($x509, $certPem)) {
                $io->error('Не удалось создать корневой сертификат: ' . (string) openssl_error_string());

                return Command::FAILURE;
            }

            if (!openssl_pkey_export($key, $keyPem, $this->caRootKeyPassphrase, ['config' => $configPath, 'encrypt_key' => true, 'encrypt_key_cipher' => OPENSSL_CIPHER_AES_256_CBC])) {
                $io->error('Не удалось экспортировать корневой ключ: ' . (string) openssl_error_string());

                return Command::FAILURE;
            }
        } finally {
            @unlink($configPath);
        }

        file_put_contents($this->caRootCertPath, $certPem);
        file_put_contents($this->caRootKeyPath, $keyPem);
        chmod($this->caRootKeyPath, 0600);

        $io->success(sprintf(
            'Корневая пара УЦ создана: сертификат %s, ключ %s (0600, зашифрован passphrase). Срок действия — 10 лет.',
            $this->caRootCertPath,
            $this->caRootKeyPath,
        ));

        return Command::SUCCESS;
    }
}
