<?php

declare(strict_types=1);

namespace App\Service\Acknowledgment;

use App\Entity\Acknowledgment\AckDocument;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Файлы документов к ознакомлению в MinIO: содержимое в бакете, в базе ключ.
 *
 * Свой бакет, а не общий с другими модулями: права, чистка и ретеншн у раздела
 * делопроизводства свои, и разбирать в общем бакете, чей это объект, пришлось бы
 * по префиксу ключа — то есть по соглашению, которое некому проверять.
 */
final class AckFileStorageService
{
    public const MAX_FILE_SIZE = 25 * 1024 * 1024;

    /**
     * Документы и сканы. SVG нет намеренно: он исполняемый, а отдаём мы файлы
     * тем же пользователям, что и загружают.
     */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg',
        'image/png',
    ];

    public function __construct(
        private readonly S3Client $s3,
        private readonly string $bucket,
    ) {
    }

    /**
     * Кладёт объект и возвращает ключ; строку заводит вызывающий.
     *
     * Имя объекта случайное, а не исходное: у делопроизводства половина файлов
     * называется «Приказ.pdf», и по имени они бы затирали друг друга.
     *
     * Порядок важен: сначала объект, потом запись в базе. В обратном порядке
     * сбой оставил бы строку, ссылающуюся в пустоту, и скачивание ломалось бы
     * молча; худшее здесь — осиротевший объект, который никому не мешает.
     */
    public function upload(AckDocument $document, UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $storageKey = sprintf(
            '%d/%s%s',
            (int) $document->getId(),
            bin2hex(random_bytes(16)),
            $extension === '' ? '' : '.' . $extension,
        );

        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $storageKey,
            'SourceFile' => $file->getPathname(),
            'ContentType' => $file->getClientMimeType() ?: 'application/octet-stream',
        ]);

        return $storageKey;
    }

    /** Тип берём из содержимого, а не из заголовка запроса: клиент врёт. */
    public function isAllowed(UploadedFile $file): bool
    {
        $mimeType = $file->getMimeType() ?: $file->getClientMimeType();

        return $mimeType !== null && in_array($mimeType, self::ALLOWED_MIME_TYPES, true);
    }

    /**
     * Тело объекта вместе с ContentType и ContentLength.
     *
     * @throws S3Exception если объекта нет
     */
    public function getObject(string $storageKey): Result
    {
        return $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key' => $storageKey,
        ]);
    }

    /**
     * Объекта может уже не быть — например, бакет чистили руками. Это не повод
     * отказываться удалять строку: иначе она останется навсегда неудаляемой.
     */
    public function delete(?string $storageKey): void
    {
        if ($storageKey === null || $storageKey === '') {
            return;
        }

        try {
            $this->s3->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $storageKey,
            ]);
        } catch (S3Exception) {
            // Уже удалён или недоступен — продолжаем.
        }
    }
}
