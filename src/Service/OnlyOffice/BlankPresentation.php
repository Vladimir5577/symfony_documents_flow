<?php

declare(strict_types=1);

namespace App\Service\OnlyOffice;

/**
 * Пустая презентация на один слайд.
 *
 * PhpOffice презентации не пишет. blank.pptx собран OnlyOffice docbuilder
 * (`builder.CreateFile("pptx")`). Файл не править руками: пересобрать тем же вызовом.
 */
final class BlankPresentation
{
    public static function create(): ?string
    {
        $source = __DIR__ . '/blank.pptx';
        if (!is_file($source)) {
            return null;
        }
        $path = tempnam(sys_get_temp_dir(), 'pptx');
        if ($path === false) {
            return null;
        }
        $target = $path . '.pptx';
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
