<?php

declare(strict_types=1);

namespace App\Service\FileGroup;

use App\Entity\FileGroup\FileGroup;
use App\Entity\FileGroup\FileGroupFile;
use App\Entity\FileGroup\FileGroupFolder;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Файлы групп в MinIO. В БД ключ — `stored_name`.
 * Порядок загрузки: сначала объект, потом строка.
 * CLI `migrateFromDisk` ещё читает старый диск на других стендах.
 */
final class FileGroupStorage
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** @var array<string, array{0: string, 1: string}> mime, OnlyOffice documentType */
    public const OFFICE = [
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'word'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'cell'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'slide'],
        'pdf' => ['application/pdf', 'pdf'],
    ];

    /** @var array<string, string> */
    public const IMAGES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    public function __construct(
        #[Autowire('%private_upload_dir%')]
        private readonly string $uploadDir,
        private readonly S3Client $s3,
        private readonly string $bucket,
    ) {
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    /** Ключ объекта: {groupId}/{hex}.{ext} — без исходного имени. */
    public function objectKey(FileGroup $group, string $originalName): string
    {
        $ext = self::extension($originalName);
        if (!preg_match('/^[a-z0-9]{1,20}$/', $ext)) {
            $ext = '';
        }

        return sprintf(
            '%d/%s%s',
            (int) $group->getId(),
            bin2hex(random_bytes(16)),
            $ext === '' ? '' : '.' . $ext,
        );
    }

    public function hasObject(string $storageKey): bool
    {
        try {
            $this->s3->headObject([
                'Bucket' => $this->bucket,
                'Key' => $storageKey,
            ]);

            return true;
        } catch (S3Exception) {
            return false;
        }
    }

    public static function extension(string $name): string
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }

    public static function mime(string $name): string
    {
        $ext = self::extension($name);

        return self::OFFICE[$ext][0] ?? self::IMAGES[$ext] ?? 'application/octet-stream';
    }

    public static function documentType(string $name): ?string
    {
        return self::OFFICE[self::extension($name)][1] ?? null;
    }

    /**
     * @throws \RuntimeException при сбое putObject
     */
    public function store(FileGroup $group, UploadedFile $upload, string $originalName, ?FileGroupFolder $folder): FileGroupFile
    {
        $size = (int) $upload->getSize();
        $storageKey = $this->objectKey($group, $originalName);

        try {
            $this->s3->putObject([
                'Bucket' => $this->bucket,
                'Key' => $storageKey,
                'SourceFile' => $upload->getPathname(),
                'ContentType' => self::mime($originalName),
            ]);
        } catch (S3Exception $e) {
            throw new \RuntimeException('file_group_upload', 0, $e);
        }

        return new FileGroupFile($group, $originalName, $storageKey, $size, $folder);
    }

    public function locate(FileGroupFile $file): ?Result
    {
        try {
            return $this->s3->getObject([
                'Bucket' => $this->bucket,
                'Key' => $file->getStoredName(),
            ]);
        } catch (S3Exception) {
            return null;
        }
    }

    /** Перезапись того же ключа (OnlyOffice save). */
    public function replace(FileGroupFile $file, string $bytes): void
    {
        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $file->getStoredName(),
            'Body' => $bytes,
            'ContentType' => self::mime($file->getStoredName()),
        ]);
    }

    /**
     * Объекта может уже не быть — строку всё равно удаляем.
     *
     * @param list<FileGroupFile> $files
     */
    public function deleteFiles(array $files): void
    {
        foreach ($files as $file) {
            $this->deleteFile($file);
        }
    }

    public function deleteFile(FileGroupFile $file): void
    {
        try {
            $this->s3->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $file->getStoredName(),
            ]);
        } catch (S3Exception) {
            // Уже нет или бакет недоступен — строку всё равно сносим.
        }
    }

    /**
     * Диск → MinIO тем же ключом. Идемпотентно. Для стендов, где CLI ещё не гоняли.
     *
     * @return 'ok'|'skip'|'missing'
     */
    public function migrateFromDisk(FileGroupFile $file, bool $deleteDisk = false): string
    {
        $key = $file->getStoredName();
        $path = $this->diskPath($file);

        if ($this->hasObject($key)) {
            if ($deleteDisk && is_file($path)) {
                @unlink($path);
            }

            return 'skip';
        }

        if (!is_file($path)) {
            return 'missing';
        }

        try {
            $this->s3->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'SourceFile' => $path,
                'ContentType' => self::mime($file->getOriginalName()),
            ]);
        } catch (S3Exception $e) {
            throw new \RuntimeException('file_group_migrate', 0, $e);
        }

        if ($deleteDisk) {
            @unlink($path);
        }

        return 'ok';
    }

    /** Локальный путь только для CLI-миграции. */
    public function diskPath(FileGroupFile $file): string
    {
        return rtrim($this->uploadDir, '/') . '/file_groups/' . $file->getGroup()->getId() . '/' . $file->getStoredName();
    }
}
