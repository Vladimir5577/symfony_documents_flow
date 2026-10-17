<?php

namespace App\Service\Document\Convertor;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

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

        // создаём временный профиль для LibreOffice
        $tmpProfile = '/tmp/lo-' . bin2hex(random_bytes(16));
        $filesystem = new Filesystem();
        try {
            $filesystem->mkdir($tmpProfile, 0700);

            // timeout завершает также дочерние процессы обёртки soffice.
            $process = new \Symfony\Component\Process\Process([
                'timeout', '-k', '5', '120',
                '/usr/bin/soffice',
                '--headless',
                '--convert-to', 'pdf',
                $docxFilePath,
                '--outdir', $this->documentsUpdatedDir,
                '-env:UserInstallation=file://' . $tmpProfile
            ]);
            $process->setTimeout(130);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \Symfony\Component\Process\Exception\ProcessFailedException($process);
            }
        } finally {
            try {
                $filesystem->remove($tmpProfile);
            } catch (\Throwable) {
                // Мусор в /tmp не повод терять результат конвертации.
            }
        }

        $pdfFilePath = $this->documentsUpdatedDir . DIRECTORY_SEPARATOR . basename($docxFilePath, '.docx') . '.pdf';

        if (!file_exists($pdfFilePath)) {
            throw new \Exception("PDF не был создан");
        }
    }
}
