<?php

declare(strict_types=1);

namespace App\Service\OnlyOffice;

/**
 * Пустая страница PDF.
 *
 * PhpOffice PDF не пишет. blank.pdf собран OnlyOffice docbuilder
 * (`builder.CreateFile("pdf")`). Файл не править руками: пересобрать тем же вызовом.
 */
final class BlankPdf
{
    public static function create(): ?string
    {
        $source = __DIR__ . '/blank.pdf';
        if (!is_file($source)) {
            return null;
        }
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        if ($path === false) {
            return null;
        }
        $target = $path . '.pdf';
        if (!@rename($path, $target)) {
            @unlink($path);

            return null;
        }
        if (!@copy($source, $target)) {
            @unlink($target);

            return null;
        }

        return $target;
    }
}
