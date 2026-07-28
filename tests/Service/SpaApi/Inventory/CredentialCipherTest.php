<?php

declare(strict_types=1);

namespace App\Tests\Service\SpaApi\Inventory;

use App\Service\SpaApi\Inventory\CredentialCipher;
use PHPUnit\Framework\TestCase;

final class CredentialCipherTest extends TestCase
{
    private function cipher(): CredentialCipher
    {
        return new CredentialCipher(base64_encode(sodium_crypto_secretbox_keygen()));
    }

    public function testRoundtrip(): void
    {
        $cipher = $this->cipher();

        $encrypted = $cipher->encrypt('admin', 'p@ssw0rd-Кириллица');

        self::assertSame(CredentialCipher::CURRENT_KEY_VERSION, $encrypted['keyVersion']);
        self::assertStringStartsWith('v1:', $encrypted['cipher']);
        self::assertStringNotContainsString('p@ssw0rd', $encrypted['cipher']);

        $decrypted = $cipher->decrypt($encrypted['cipher']);

        self::assertSame('admin', $decrypted['login']);
        self::assertSame('p@ssw0rd-Кириллица', $decrypted['password']);
    }

    public function testNullLoginAllowed(): void
    {
        $cipher = $this->cipher();

        $decrypted = $cipher->decrypt($cipher->encrypt(null, 'only-password')['cipher']);

        self::assertNull($decrypted['login']);
        self::assertSame('only-password', $decrypted['password']);
    }

    public function testEachEncryptionUsesFreshNonce(): void
    {
        $cipher = $this->cipher();

        self::assertNotSame(
            $cipher->encrypt('a', 'b')['cipher'],
            $cipher->encrypt('a', 'b')['cipher'],
        );
    }

    public function testDecryptWithWrongKeyFails(): void
    {
        $encrypted = $this->cipher()->encrypt('admin', 'secret');

        $this->expectException(\RuntimeException::class);
        $this->cipher()->decrypt($encrypted['cipher']);
    }

    public function testInvalidEnvelopeFormatFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->cipher()->decrypt('not-an-envelope');
    }

    public function testUnknownKeyVersionFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->cipher()->decrypt('v99:' . base64_encode(random_bytes(48)));
    }

    public function testMissingKeyIsReportedAsNotConfigured(): void
    {
        $cipher = new CredentialCipher('');

        self::assertFalse($cipher->isConfigured());

        $this->expectException(\RuntimeException::class);
        $cipher->encrypt('a', 'b');
    }
}
