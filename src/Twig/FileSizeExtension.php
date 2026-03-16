<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class FileSizeExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('format_file_size', [$this, 'formatFileSize']),
        ];
    }

    public function formatFileSize(?string $bytes): string
    {
        if ($bytes === null || $bytes === '') {
            return '';
        }

        $size = (float) $bytes;

        if ($size < 1024) {
            return round($size) . ' Б';
        }

        if ($size < 1024 * 1024) {
            return round($size / 1024, 1) . ' КБ';
        }

        if ($size < 1024 * 1024 * 1024) {
            return round($size / (1024 * 1024), 1) . ' МБ';
        }

        return round($size / (1024 * 1024 * 1024), 1) . ' ГБ';
    }
}
