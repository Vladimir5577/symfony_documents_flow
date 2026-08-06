<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Entity\Purchase\PurchaseRequest;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Вложения заявок на закупку в MinIO: содержимое в бакете, в базе только ключ объекта.
 *
 * Тип и размер объекта отдельно не храним — их возвращает сам MinIO вместе с телом
 * при скачивании, а лишний столбец пришлось бы поддерживать в согласии с бакетом.
 */
final class PurchaseFileStorageService
{
    public function __construct(
        private readonly S3Client $s3,
        private readonly string $bucket,
    ) {
    }

    /**
     * Кладёт объект и возвращает его ключ; строку заводит вызывающий.
     *
     * Порядок важен: сначала объект, потом запись. В обратном порядке при сбое
     * осталась бы строка, ссылающаяся в пустоту, и скачивание ломалось бы молча;
     * худшее же, что бывает здесь, — осиротевший объект, который никому не мешает.
     */
    public function upload(PurchaseRequest $purchase, UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $storageKey = sprintf(
            '%d/%s%s',
            $purchase->getId(),
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
    public function delete(string $storageKey): void
    {
        try {
            $this->s3->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $storageKey,
            ]);
        } catch (S3Exception) {
            // Уже удалён или недоступен — продолжаем.
        }
    }

    /**
     * Снести вложения заявки перед удалением самой заявки.
     *
     * Строки уедут сами (orphanRemoval), а объекты остались бы висеть в бакете
     * навсегда, и найти их потом было бы уже нечем: ключи хранятся только в них.
     */
    public function deleteAllFor(PurchaseRequest $purchase): void
    {
        foreach ($purchase->getFiles() as $file) {
            $this->delete($file->getStorageKey());
        }
    }
}
