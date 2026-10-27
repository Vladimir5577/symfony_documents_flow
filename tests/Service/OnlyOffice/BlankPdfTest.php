<?php

declare(strict_types=1);

namespace App\Tests\Service\OnlyOffice;

use App\Service\OnlyOffice\BlankPdf;
use PHPUnit\Framework\TestCase;

/** Пустой pdf, который Document Server открывает как pdf. */
final class BlankPdfTest extends TestCase
{
    public function testTemplateIsAPdf(): void
    {
        $path = BlankPdf::create();
        self::assertNotNull($path);

        try {
            $head = file_get_contents($path, false, null, 0, 8);
            self::assertSame('%PDF-1.7', $head);
        } finally {
            @unlink($path);
        }
    }
}
