<?php

declare(strict_types=1);

namespace App\Tests\Service\Purchase;

use App\Service\Purchase\PurchaseFileStorageService;
use App\Service\Purchase\PurchaseImageUrlGenerator;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Картинки справочника закупок наружу уходят только через imgproxy, и тип
 * определяется по содержимому: эндпоинта «скачать оригинал» для них нет, так что
 * дырка возможна ровно одна — пустить внутрь то, что imgproxy отдаст как есть.
 */
final class PurchaseFileStorageServiceTest extends TestCase
{
    private function storage(): PurchaseFileStorageService
    {
        // Запросов в S3 тут не будет: проверяем только белый список.
        return new PurchaseFileStorageService(
            new S3Client([
                'region' => 'us-east-1',
                'version' => 'latest',
                'credentials' => ['key' => 'test', 'secret' => 'test'],
            ]),
            'purchase',
        );
    }

    public function testImageUrlIsNullWithoutKey(): void
    {
        $generator = new PurchaseImageUrlGenerator('/media/preview', 'purchase');

        self::assertNull($generator->getImageUrl(null));
        self::assertNull($generator->getImageUrl(''));
    }

    public function testImageUrlGoesThroughImgproxy(): void
    {
        $generator = new PurchaseImageUrlGenerator('/media/preview', 'purchase');

        self::assertSame(
            '/media/preview/unsafe/rs:fit:96:96/plain/s3://purchase/categories/3/abc.png',
            $generator->getImageUrl('categories/3/abc.png'),
        );
    }

    public function testRasterImageIsAllowed(): void
    {
        self::assertTrue($this->storage()->isAllowedImage($this->file('pic.png', $this->png(), 'image/png')));
    }

    /** Клиентскому Content-Type веры нет: тип берётся из содержимого файла. */
    public function testSvgDisguisedAsPngIsRejected(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        self::assertFalse($this->storage()->isAllowedImage($this->file('pic.png', $svg, 'image/png')));
    }

    private function file(string $name, string $content, string $clientMimeType): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'purchase_image');
        self::assertIsString($path);
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $clientMimeType, null, true);
    }

    private function png(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
    }
}
