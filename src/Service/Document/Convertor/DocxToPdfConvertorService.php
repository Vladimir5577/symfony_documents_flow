<?php

namespace App\Service\Document\Convertor;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
//        $projectDir = $this->getParameter('kernel.project_dir');
//        $outputDir = $projectDir . '/public/files'; // абсолютный путь
//        $docxFilePath = $this->documentsOriginalsDir . DIRECTORY_SEPARATOR . $docxFileName; // файл который загружен

        // создаём временный профиль для LibreOffice
        $tmpProfile = '/tmp/libreoffice_profile';
        if (!is_dir($tmpProfile)) {
            mkdir($tmpProfile, 0777, true);
        }

        // запуск LibreOffice для конвертации DOCX → PDF
        $process = new \Symfony\Component\Process\Process([
            '/usr/bin/soffice', // путь к бинарнику LibreOffice
            '--headless',
            '--convert-to', 'pdf',
            $docxFilePath,
            '--outdir', $this->documentsUpdatedDir,
            '-env:UserInstallation=file://' . $tmpProfile
        ]);

        $process->run();

        // проверка на ошибки
        if (!$process->isSuccessful()) {
            throw new \Symfony\Component\Process\Exception\ProcessFailedException($process);
        }

        // путь к PDF
        $pdfFilePath = $this->documentsUpdatedDir . DIRECTORY_SEPARATOR . basename($docxFilePath, '.docx') . '.pdf';

        // проверка, что файл создан
        if (!file_exists($pdfFilePath)) {
            throw new \Exception("PDF не был создан");
        }
    }
}
