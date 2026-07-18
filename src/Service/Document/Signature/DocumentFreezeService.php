<?php

namespace App\Service\Document\Signature;

use App\Entity\Document\Document;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class DocumentFreezeService
{
    private const SOFFICE_BINARY = '/usr/bin/soffice';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%private_upload_dir_documents_originals%')]
        private readonly string $documentsOriginalsDir,
        #[Autowire('%private_upload_dir_documents_canonical%')]
        private readonly string $documentsCanonicalDir,
    ) {
    }

    /**
     * Замораживает документ: канонический PDF (DOC/DOCX конвертируется через LibreOffice),
     * SHA-256 хэш и код проверки. Замороженный документ повторно не замораживается.
     */
    public function freeze(Document $document): void
    {
        if ($document->getCanonicalFile() !== null) {
            throw new \LogicException('Документ уже заморожен: канонический файл не перезаписывается.');
        }

        $originalFile = $document->getOriginalFile();
        if (!$originalFile) {
            throw new \RuntimeException('У документа нет файла для заморозки.');
        }

        $sourcePath = $this->documentsOriginalsDir . \DIRECTORY_SEPARATOR . basename($originalFile);
        if (!is_file($sourcePath)) {
            throw new \RuntimeException('Исходный файл документа не найден: ' . basename($originalFile));
        }

        if (!is_dir($this->documentsCanonicalDir)) {
            mkdir($this->documentsCanonicalDir, 0775, true);
        }

        $canonicalFileName = bin2hex(random_bytes(16)) . '.pdf';
        $canonicalPath = $this->documentsCanonicalDir . \DIRECTORY_SEPARATOR . $canonicalFileName;

        $extension = strtolower(pathinfo($originalFile, PATHINFO_EXTENSION));
        if (in_array($extension, ['doc', 'docx'], true)) {
            $convertedPath = $this->convertToPdf($sourcePath);
            rename($convertedPath, $canonicalPath);
        } elseif ($extension === 'pdf') {
            copy($sourcePath, $canonicalPath);
        } else {
            throw new \RuntimeException('Неподдерживаемый формат файла для заморозки: ' . $extension);
        }

        $document->setCanonicalFile($canonicalFileName);
        $document->setCanonicalFileHash(hash_file('sha256', $canonicalPath));
        $document->setVerificationCode(substr(bin2hex(random_bytes(8)), 0, 16));

        $this->entityManager->flush();
    }

    /**
     * Конвертация DOC/DOCX → PDF через LibreOffice (headless).
     * Логика перенесена из старого прототипа подписания (удалён в Фазе 7).
     * Возвращает путь к сконвертированному PDF в canonical-директории.
     */
    private function convertToPdf(string $sourcePath): string
    {
        // создаём временный профиль для LibreOffice
        $tmpProfile = '/tmp/libreoffice_profile';
        if (!is_dir($tmpProfile)) {
            mkdir($tmpProfile, 0777, true);
        }

        // запуск LibreOffice для конвертации DOCX → PDF
        $process = new Process([
            self::SOFFICE_BINARY,
            '--headless',
            '--convert-to', 'pdf',
            $sourcePath,
            '--outdir', $this->documentsCanonicalDir,
            '-env:UserInstallation=file://' . $tmpProfile,
        ]);

        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $pdfPath = $this->documentsCanonicalDir . \DIRECTORY_SEPARATOR
            . pathinfo($sourcePath, PATHINFO_FILENAME) . '.pdf';

        if (!file_exists($pdfPath)) {
            throw new \RuntimeException('PDF не был создан');
        }

        return $pdfPath;
    }
}
