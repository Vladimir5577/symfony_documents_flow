<?php

namespace App\Service\Document;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class FileUploadService
{
    private const MAX_SIZE_BYTES = 9 * 1024 * 1024; // 5 МБ

    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/msword',                                                      // .doc
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
//        'image/jpeg',
//        'image/jpg',
//        'image/png',
    ];

    public function __construct(
        #[Autowire('%private_upload_dir_documents_originals%')]
        private readonly string $documentsOriginalsDir,
        #[Autowire('%private_upload_dir_documents_updated%')]
        private readonly string $documentsUpdatedDir,
    ) {
    }

    /**
     * Загружает файл. Возвращает массив: ['fileName' => string|null, 'error' => string|null].
     */
    public function uploadOriginalFile(UploadedFile $file): array
    {
        $result = [
            'fileName' => null,
            'error' => null,
        ];

        if (!$file->isValid()) {
            $result['error'] = $file->getErrorMessage() ?: 'Выберите файл (PDF, DOC, DOCX, JPEG или PNG).';
            return $result;
        }

        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            $result['error'] = 'Файл слишком большой (максимум 9 МБ).';
            return $result;
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            $result['error'] = 'Допустимые форматы: PDF, DOC, DOCX, JPEG, PNG.';
            return $result;
        }

        if (!is_dir($this->documentsOriginalsDir)) {
            throw new \LogicException('Can not get upload directory.');
        }

        $filename = $this->generateFileName() . '.' . ($file->guessExtension() ?? 'bin');
        $file->move($this->documentsOriginalsDir, $filename);

        $result['fileName'] = $filename;
        return $result;
    }

    /**
     * Копирует уже загруженный в originals файл в папку updated.
     * Вызывать после uploadOriginalFile — оригинал уже перемещён, повторный move() невозможен.
     */
    public function copyOriginalPDFToUpdated(string $fileName): void
    {
        $sourcePath = $this->documentsOriginalsDir . \DIRECTORY_SEPARATOR . basename($fileName);
        $targetPath = $this->documentsUpdatedDir . \DIRECTORY_SEPARATOR . basename($fileName);
        if (is_file($sourcePath) && is_dir($this->documentsUpdatedDir)) {
            copy($sourcePath, $targetPath);
        }
    }

    public function deleteOriginalFile(string $fileName): void
    {
        $this->deleteFile($fileName, $this->documentsOriginalsDir);
    }

    public function deleteUpdatedFile(string $fileName): void
    {
        $this->deleteFile($fileName, $this->documentsUpdatedDir);
    }

    public function generateFileName(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Удаляет файл из папки по имени файла.
     * Если файла нет — метод завершается без ошибки.
     */
    private function deleteFile(string $fileName, $directory): void
    {
        $fileName = basename($fileName);

        $path = $directory . \DIRECTORY_SEPARATOR . $fileName;
        if (!is_file($path)) {
            return;
        }
        $dirReal = realpath($directory);
        $fileReal = realpath($path);
        if ($dirReal !== false && $fileReal !== false && str_starts_with($fileReal, $dirReal . \DIRECTORY_SEPARATOR)) {
            unlink($path);
        }
    }
}
