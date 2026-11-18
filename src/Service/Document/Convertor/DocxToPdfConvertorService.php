<?php

namespace App\Service\Document\Convertor;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class DocxToPdfConvertorService
{
    public function __construct(
        #[Autowire('%private_upload_dir_documents_originals%')]
        private readonly string $documentsOriginalsDir,
        #[Autowire('%private_upload_dir_documents_updated%')]
        private readonly string $documentsUpdatedDir,
    ) {
    }

    public function convertDocxToPdfFromOriginals(string $fileName): void
    {
        $targetFile = rtrim($this->documentsOriginalsDir, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . basename($fileName);
        if (!is_file($targetFile)) {
            throw new \RuntimeException('Исходный файл не найден: ' . $targetFile);
        }
        $this->convertDocxToPdf($targetFile);
    }

    public function convertDocxToPdf(string $docxFilePath): void
    {
        $this->convert($docxFilePath, $this->documentsUpdatedDir);
    }

    /**
     * Кладёт {имя docx}.pdf в $outDir и возвращает путь к нему.
     * У каждого запуска свой профиль LibreOffice: с общим два одновременных
     * запуска мешают друг другу.
     */
    public function convert(string $docxFilePath, string $outDir): string
    {
        $profile = sys_get_temp_dir() . '/libreoffice_profile_' . bin2hex(random_bytes(8));

        $process = new Process([
            '/usr/bin/soffice',
            '--headless',
            '-env:UserInstallation=file://' . $profile,
            '--convert-to', 'pdf',
            '--outdir', $outDir,
            $docxFilePath,
        ]);
        $process->setTimeout(120);

        try {
            $process->run();
        } finally {
            (new Filesystem())->remove($profile);
        }

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $pdfFilePath = rtrim($outDir, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . pathinfo($docxFilePath, PATHINFO_FILENAME) . '.pdf';
        if (!is_file($pdfFilePath)) {
            throw new \RuntimeException('PDF не был создан');
        }

        return $pdfFilePath;
    }
}
