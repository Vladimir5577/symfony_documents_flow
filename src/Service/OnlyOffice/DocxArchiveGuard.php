<?php

declare(strict_types=1);

namespace App\Service\OnlyOffice;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class DocxArchiveGuard
{
    // Bound ZIP expansion before XML parsing; compressed upload limits do not stop ZIP bombs.
    public const MAX_FILES = 2048;
    public const MAX_ENTRY_BYTES = 8 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 64 * 1024 * 1024;

    public static function validate(\ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_FILES) {
            throw new HttpException(413, 'DOCX archive has too many entries.');
        }

        $total = 0;
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $entry = $zip->statIndex($index);
            if ($entry === false || !isset($entry['size']) || !is_int($entry['size']) || $entry['size'] < 0) {
                throw new HttpException(422, 'Invalid DOCX archive metadata.');
            }
            if ($entry['size'] > self::MAX_ENTRY_BYTES || $entry['size'] > self::MAX_TOTAL_BYTES - $total) {
                throw new HttpException(413, 'DOCX archive exceeds the unpacked size limit.');
            }
            $total += $entry['size'];
        }

        if ($zip->locateName('word/document.xml') === false) {
            throw new HttpException(422, 'Invalid DOCX archive.');
        }
    }

    /** Call validate() before reading any entries from the archive. */
    public static function read(\ZipArchive $zip, string $name): string
    {
        $content = $zip->getFromName($name, self::MAX_ENTRY_BYTES + 1);
        if (is_string($content) && strlen($content) > self::MAX_ENTRY_BYTES) {
            throw new HttpException(413, 'DOCX archive exceeds the unpacked size limit.');
        }
        $entry = $zip->statName($name);
        if (!is_string($content) || $entry === false || strlen($content) !== $entry['size']) {
            throw new HttpException(422, 'Invalid DOCX archive entry.');
        }

        return $content;
    }

    public static function validateContent(string $content): void
    {
        $path = tempnam(sys_get_temp_dir(), 'docx-check');
        if ($path === false) {
            throw new \RuntimeException('Cannot create DOCX validation file.');
        }
        $zip = new \ZipArchive();
        $opened = false;
        try {
            if (file_put_contents($path, $content) !== strlen($content)) {
                throw new \RuntimeException('Cannot write DOCX validation file.');
            }
            $opened = $zip->open($path) === true;
            if (!$opened) {
                throw new HttpException(422, 'Invalid DOCX archive.');
            }
            self::validate($zip);
        } finally {
            if ($opened) {
                $zip->close();
            }
            @unlink($path);
        }
    }
}
