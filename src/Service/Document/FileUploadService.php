<?php

namespace App\Service\Document;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FileUploadService
{
    private const MAX_SIZE_BYTES = 5 * 1024 * 1024; // 5 МБ

    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/msword',                                                      // .doc
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
        'image/jpeg',
        'image/png',
    ];

    public function __construct(
        #[Autowire('%private_upload_dir_documents_originals%')]
        private readonly string $documentsOriginalsDir,
    ) {
    }

    /**
     * Загружает файл. Возвращает массив: ['fileName' => string|null, 'error' => string|null].
     */
    public function uploadFile(UploadedFile $file): array
    {
        $result = [
            'fileName' => null,
            'error' => null,
        ];

        if (!$file->isValid()) {
            $result['error'] = 'Выберите файл (PDF, DOC, DOCX, JPEG или PNG).';
            return $result;
        }

        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            $result['error'] = 'Файл слишком большой (максимум 5 МБ).';
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
     * Возвращает полный путь к файлу в папке оригиналов по имени файла.
     * Имя файла — то, что возвращает uploadFile() (например, из document.originalFile).
     */
    public function getFilePath(string $fileName): string
    {
        $fileName = basename($fileName);
        return $this->documentsOriginalsDir . \DIRECTORY_SEPARATOR . $fileName;
    }

    public function generateFileName(): string
    {
        return bin2hex(random_bytes(16));
    }
}
