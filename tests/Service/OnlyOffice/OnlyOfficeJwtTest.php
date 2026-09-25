<?php

declare(strict_types=1);

namespace App\Tests\Service\OnlyOffice;

use App\Service\OnlyOffice\OnlyOfficeJwt;
use PHPUnit\Framework\TestCase;

/** Подпись обмена с Document Server: чужой или испорченный токен не проходит. */
final class OnlyOfficeJwtTest extends TestCase
{
    public function testSignedPayloadRoundTrips(): void
    {
        $jwt = new OnlyOfficeJwt('secret');
        $payload = ['document' => ['key' => 'pf1r1-abc', 'title' => 'Договор.docx'], 'status' => 2];

        self::assertSame($payload, $jwt->decode($jwt->encode($payload)));
    }

    public function testTamperedOrForeignTokenIsRejected(): void
    {
        $jwt = new OnlyOfficeJwt('secret');
        [$header, , $signature] = explode('.', $jwt->encode(['status' => 2]));
        $forged = $header . '.' . rtrim(strtr(base64_encode('{"status":6}'), '+/', '-_'), '=') . '.' . $signature;

        self::assertNull($jwt->decode($forged));
        self::assertNull($jwt->decode((new OnlyOfficeJwt('other'))->encode(['status' => 2])));
        self::assertNull($jwt->decode('not-a-token'));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $jwt = new OnlyOfficeJwt('secret');

        self::assertNull($jwt->decode($jwt->encode(['status' => 2, 'exp' => time() - 10])));
    }

    public function testWithoutSecretSigningIsOff(): void
    {
        $jwt = new OnlyOfficeJwt(null);

        self::assertFalse($jwt->isEnabled());
        self::assertNull($jwt->decode((new OnlyOfficeJwt('secret'))->encode(['status' => 2])));
    }
}
