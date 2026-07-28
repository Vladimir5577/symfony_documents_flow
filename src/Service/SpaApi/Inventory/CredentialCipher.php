<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Inventory;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Шифрование доступов устройств (логин + пароль) единым envelope.
 *
 * Формат хранения: "v{key_version}:" . base64(nonce || tag || cipher).
 * Ключ — 32 байта, base64 в env INVENTORY_CREDENTIALS_KEY (не хранится в БД и её бэкапах).
 * Ротация: новый ключ = новая версия; decrypt выбирает ключ по префиксу версии,
 * перешифровка выполняется отдельной командой (бэклог).
 *
 * Секреты НИКОГДА не пишутся в логи, inventory_history.payload и ответы без reveal.
 */
final class CredentialCipher
{
    public const CURRENT_KEY_VERSION = 1;

    public function __construct(
        #[Autowire('%env(INVENTORY_CREDENTIALS_KEY)%')]
        private readonly string $keyBase64,
    ) {
    }

    /**
     * @return array{cipher: string, keyVersion: int}
     */
    public function encrypt(?string $login, ?string $password): array
    {
        $key = $this->keyForVersion(self::CURRENT_KEY_VERSION);
        $nonce = random_bytes(12);
        $plain = json_encode(
            ['login' => $login, 'password' => $password],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );

        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        $plain = str_repeat("\0", \strlen($plain));

        return [
            'cipher' => sprintf('v%d:%s', self::CURRENT_KEY_VERSION, base64_encode($nonce . $tag . $cipher)),
            'keyVersion' => self::CURRENT_KEY_VERSION,
        ];
    }

    /**
     * @return array{login: ?string, password: ?string}
     */
    public function decrypt(string $envelope): array
    {
        if (!preg_match('/^v(\d+):(.+)$/', $envelope, $m)) {
            throw new \RuntimeException('Inventory credential envelope has invalid format');
        }

        $key = $this->keyForVersion((int) $m[1]);
        $raw = base64_decode($m[2], true);
        if ($raw === false || \strlen($raw) <= 12 + 16) {
            throw new \RuntimeException('Inventory credential envelope is corrupted');
        }

        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Inventory credential envelope failed to decrypt (wrong key or tampered data)');
        }

        /** @var array{login: ?string, password: ?string} $data */
        $data = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
        $plain = str_repeat("\0", \strlen((string) $plain));

        return ['login' => $data['login'] ?? null, 'password' => $data['password'] ?? null];
    }

    public function isConfigured(): bool
    {
        try {
            $this->keyForVersion(self::CURRENT_KEY_VERSION);

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    private function keyForVersion(int $version): string
    {
        // Пока существует единственная версия ключа; при ротации добавить карту env-переменных.
        if ($version !== self::CURRENT_KEY_VERSION) {
            throw new \RuntimeException(sprintf('Unknown inventory credential key version v%d', $version));
        }

        if ($this->keyBase64 === '') {
            throw new \RuntimeException('INVENTORY_CREDENTIALS_KEY is not configured');
        }

        $key = base64_decode($this->keyBase64, true);
        if ($key === false || \strlen($key) !== 32) {
            throw new \RuntimeException('INVENTORY_CREDENTIALS_KEY must be base64 of exactly 32 bytes');
        }

        return $key;
    }
}
