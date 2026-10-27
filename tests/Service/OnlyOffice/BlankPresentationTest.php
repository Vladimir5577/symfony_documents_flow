<?php

declare(strict_types=1);

namespace App\Tests\Service\OnlyOffice;

use App\Service\OnlyOffice\BlankPresentation;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/** Пустой pptx, который Document Server открывает как slide. */
final class BlankPresentationTest extends TestCase
{
    public function testTemplateIsAOneSlidePresentation(): void
    {
        $path = BlankPresentation::create();
        self::assertNotNull($path);

        try {
            $zip = new ZipArchive();
            self::assertTrue($zip->open($path) === true);
            self::assertNotFalse($zip->locateName('ppt/slides/slide1.xml'));
            $types = $zip->getFromName('[Content_Types].xml');
            self::assertIsString($types);
            self::assertStringContainsString(
                'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml',
                $types,
            );
            $zip->close();
        } finally {
            @unlink($path);
        }
    }
}
