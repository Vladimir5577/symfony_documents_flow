<?php

declare(strict_types=1);

namespace App\Tests\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\Purchase\PurchaseFileEditorController;
use PHPUnit\Framework\TestCase;

/**
 * Адрес собранного файла из callback: скачиваем только из кэша Document Server.
 * Иначе подделанный callback читал бы файлы сервера и внутренние сервисы.
 */
final class PurchaseFileEditorDownloadUrlTest extends TestCase
{
    private const PUBLIC = 'http://31.133.49.124:8078';

    public function testDocumentServerCacheUrlIsMappedToInternalHost(): void
    {
        self::assertSame(
            'http://onlyoffice:80/cache/files/data/pf77r1-abc_1/output.docx/output.docx?md5=x&expires=1',
            PurchaseFileEditorController::internalDownloadUrl(
                self::PUBLIC . '/cache/files/data/pf77r1-abc_1/output.docx/output.docx?md5=x&expires=1',
                self::PUBLIC,
            ),
        );
        self::assertSame(
            'http://onlyoffice:80/cache/files/data/k/output.docx',
            PurchaseFileEditorController::internalDownloadUrl('http://onlyoffice/cache/files/data/k/output.docx', self::PUBLIC),
        );
    }

    public function testDocumentServerBehindPathPrefix(): void
    {
        self::assertSame(
            'http://onlyoffice:80/cache/files/data/k/output.docx',
            PurchaseFileEditorController::internalDownloadUrl(
                'https://docs.example.org/onlyoffice/cache/files/data/k/output.docx',
                'https://docs.example.org/onlyoffice/',
            ),
        );
    }

    public function testAnythingElseIsRejected(): void
    {
        foreach ([
            'file:///var/www/html/.env',
            'php://filter/resource=/var/www/html/.env',
            'http://nginx_web/spa/api/users',
            'http://169.254.169.254/latest/meta-data/',
            self::PUBLIC . '/welcome/',
            'http://31.133.49.124:9999/cache/files/data/k/output.docx',
            'https://31.133.49.124:8078/cache/files/data/k/output.docx',
            '',
        ] as $url) {
            self::assertNull(PurchaseFileEditorController::internalDownloadUrl($url, self::PUBLIC), $url);
        }
    }
}
