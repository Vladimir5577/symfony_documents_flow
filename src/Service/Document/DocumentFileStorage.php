<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\Document\DocumentCommentFile;
use App\Entity\Document\File;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Вложения документов и файлы комментариев в MinIO.
 *
 * Ключ = путь, по которому файл лежал на диске, с префиксом папки:
 * originals/{documentId}/{имя} и comments/{documentId}/{имя}. Поэтому перенос
 * старых файлов — простое копирование, в базе ничего переписывать не нужно.
 */
final class DocumentFileStorage
{
    public const ATTACHMENTS = 'originals/';
    public const COMMENTS = 'comments/';

    public function __construct(
        private readonly S3Client $s3,
        private readonly string $bucket,
    ) {
    }

    /** Путь относительно старой папки originals; null — файла нет. */
    public static function attachmentPath(File $file): ?string
    {
        $name = $file->getFilePath();
        if ($name === null || $name === '') {
            return null;
        }

        // Старые строки хранят путь вместе с папкой документа.
        return str_contains($name, '/') ? $name : $file->getDocument()?->getId() . '/' . $name;
    }

    public static function commentFilePath(DocumentCommentFile $file): ?string
    {
        $name = $file->getStorageKey();
        if ($name === null || $name === '') {
            return null;
        }

        return $file->getComment()->getDocument()->getId() . '/' . $name;
    }

    public static function attachmentKey(File $file): ?string
    {
        $path = self::attachmentPath($file);

        return $path === null ? null : self::ATTACHMENTS . $path;
    }

    public static function commentFileKey(DocumentCommentFile $file): ?string
    {
        $path = self::commentFilePath($file);

        return $path === null ? null : self::COMMENTS . $path;
    }

    /** У документа уже должен быть id: он входит в ключ. */
    public function storeAttachment(File $file, UploadedFile $uploaded): void
    {
        $file->setFilePath(self::newName($uploaded));
        $file->setContentType($this->upload((string) self::attachmentKey($file), $uploaded));
        $file->setSizeBytes((int) $uploaded->getSize());
    }

    public function storeCommentFile(DocumentCommentFile $file, UploadedFile $uploaded): void
    {
        $file->setStorageKey(self::newName($uploaded));
        $file->setFilename($uploaded->getClientOriginalName());
        $file->setContentType($this->upload((string) self::commentFileKey($file), $uploaded));
        $file->setSizeBytes((int) $uploaded->getSize());
    }

    /** Перезапись объекта, для сохранения из редактора. false — MinIO не принял. */
    public function put(string $key, string $bytes, ?string $contentType): bool
    {
        try {
            $this->s3->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => $bytes,
                'ContentType' => $contentType ?: 'application/octet-stream',
            ]);
        } catch (S3Exception) {
            return false;
        }

        return true;
    }

    public function putFile(string $key, string $path, ?string $contentType): void
    {
        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'SourceFile' => $path,
            'ContentType' => $contentType ?: 'application/octet-stream',
        ]);
    }

    /** Скачивает объект в локальный файл. false — объекта нет. */
    public function saveTo(?string $key, string $path): bool
    {
        if ($key === null) {
            return false;
        }

        try {
            $this->s3->getObject(['Bucket' => $this->bucket, 'Key' => $key, 'SaveAs' => $path]);
        } catch (S3Exception) {
            return false;
        }

        return true;
    }

    public function exists(string $key): bool
    {
        return $this->s3->doesObjectExistV2($this->bucket, $key);
    }

    /** null — объекта нет. */
    public function response(?string $key, string $name, bool $inline): ?StreamedResponse
    {
        if ($key === null) {
            return null;
        }

        try {
            $object = $this->s3->getObject(['Bucket' => $this->bucket, 'Key' => $key]);
        } catch (S3Exception) {
            return null;
        }

        $stream = $object['Body'];
        $response = new StreamedResponse(static function () use ($stream): void {
            while (!$stream->eof()) {
                echo $stream->read(8192);
            }
        });
        $response->headers->set('Content-Type', (string) ($object['ContentType'] ?? 'application/octet-stream'));
        if ($object['ContentLength'] !== null) {
            $response->headers->set('Content-Length', (string) $object['ContentLength']);
        }
        // Имена кириллические, а makeDisposition() требует ASCII-запасной вариант.
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $name,
            preg_replace('/[^\x20-\x7e]|%/', '_', $name) ?? 'file',
        ));

        return $response;
    }

    /** Объекта может уже не быть — строку это удалять не мешает. */
    public function delete(?string $key): void
    {
        if ($key === null) {
            return;
        }

        try {
            $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
        } catch (S3Exception) {
        }
    }

    /** Возвращает тип по содержимому, а не по заголовку запроса. */
    private function upload(string $key, UploadedFile $uploaded): string
    {
        $contentType = $uploaded->getMimeType() ?: $uploaded->getClientMimeType() ?: 'application/octet-stream';
        $this->putFile($key, $uploaded->getPathname(), $contentType);

        return $contentType;
    }

    /** Случайное имя: исходные совпадают («Приказ.pdf») и затирали бы друг друга. */
    private static function newName(UploadedFile $uploaded): string
    {
        $ext = strtolower($uploaded->getClientOriginalExtension());
        if (preg_match('/^[a-z0-9]{1,10}$/', $ext) !== 1) {
            $ext = (string) $uploaded->guessExtension();
        }

        return bin2hex(random_bytes(16)) . ($ext === '' ? '' : '.' . $ext);
    }
}
