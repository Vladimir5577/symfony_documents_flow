<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;

/**
 * Договор в старом формате Word (.doc) → docx при загрузке.
 *
 * Редактор, рецензирование, подсветка отделов и копия без правок разбирают
 * docx как zip с word/document.xml (PurchaseFileCoEditing). Поэтому .doc в
 * хранилище не попадает: дальше договор живёт как обычный docx.
 */
final class PurchaseContractConverter
{
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    /**
     * Сигнатура содержимого → фильтр чтения LibreOffice. Фильтр задаём сами:
     * иначе LibreOffice угадывает формат по содержимому и откроет под видом
     * .doc что угодно, например HTML со ссылками на внешние адреса.
     */
    private const INPUT_FILTERS = [
        "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" => 'MS Word 97',
        '{\rtf' => 'Rich Text Format',
    ];

    /** С запасом до 60 секунд, которые nginx ждёт ответа. */
    private const TIMEOUT_SECONDS = 40;

    public static function isLegacyDoc(UploadedFile $file): bool
    {
        return strtolower($file->getClientOriginalExtension()) === 'doc';
    }

    /**
     * «Договор.doc» → «Договор.docx»: имя должно совпадать с содержимым.
     * Колонка имени — 255: режем основу, а не расширение.
     */
    public static function docxName(string $originalName): string
    {
        return mb_substr(preg_replace('/\.doc$/iu', '', $originalName) ?? $originalName, 0, 250) . '.docx';
    }

    /**
     * Временный docx с именем «….docx»; удаляет его вызывающий.
     * null — файл не Word или LibreOffice его не прочитал.
     */
    public function toDocx(UploadedFile $file): ?UploadedFile
    {
        $filter = self::inputFilter($file->getPathname());
        if ($filter === null) {
            return null;
        }

        $fs = new Filesystem();
        // Свой каталог и свой профиль на каждый запуск: общий профиль LibreOffice
        // блокируется, и вторая одновременная загрузка упала бы.
        $dir = sys_get_temp_dir() . '/pfdoc' . bin2hex(random_bytes(8));
        try {
            $fs->mkdir($dir . '/profile');
            $fs->copy($file->getPathname(), $dir . '/contract.doc');

            // soffice — обёртка, сам LibreOffice её потомок: сигнал Process до него
            // не дойдёт. timeout гасит всю группу процессов.
            $process = new Process([
                'timeout', '-k', '5', (string) self::TIMEOUT_SECONDS,
                '/usr/bin/soffice',
                '--headless',
                '--norestore',
                '-env:UserInstallation=file://' . $dir . '/profile',
                '--infilter=' . $filter,
                '--convert-to', 'docx:MS Word 2007 XML',
                '--outdir', $dir,
                $dir . '/contract.doc',
            ]);
            $process->setTimeout(self::TIMEOUT_SECONDS + 10);
            $process->run();

            $docx = $dir . '/contract.docx';
            if (!$process->isSuccessful() || !is_file($docx) || !self::startsWith($docx, "PK\x03\x04")) {
                return null;
            }

            $result = sys_get_temp_dir() . '/pfdocx' . bin2hex(random_bytes(8));
            $fs->rename($docx, $result);

            // test: файл не из запроса, is_uploaded_file() его бы не признал.
            return new UploadedFile($result, self::docxName($file->getClientOriginalName()), self::DOCX_MIME, null, true);
        } catch (\Throwable) {
            // Таймаут, нет LibreOffice, нет места: для загрузки это один исход.
            return null;
        } finally {
            try {
                $fs->remove($dir);
            } catch (\Throwable) {
                // Мусор в /tmp не повод ронять загрузку.
            }
        }
    }

    private static function inputFilter(string $path): ?string
    {
        foreach (self::INPUT_FILTERS as $signature => $filter) {
            if (self::startsWith($path, $signature)) {
                return $filter;
            }
        }

        return null;
    }

    private static function startsWith(string $path, string $signature): bool
    {
        return @file_get_contents($path, false, null, 0, strlen($signature)) === $signature;
    }
}
