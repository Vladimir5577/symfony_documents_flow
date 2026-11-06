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
 * Файлы групп: новые — в MinIO, старые с диска читаются dual-read до CLI-миграции.
 *
 * В БД ключ лежит в `stored_name` (как у закупок storage_key). Порядок загрузки:
 * сначала объект, потом строка — иначе UI покажет файл, который нельзя скачать.
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
     * Сначала объект в MinIO, потом сущность. `stored_name` = ключ объекта.
     *
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

    /**
     * Dual-read: MinIO по ключу, иначе старый диск.
     *
     * @return array{source: 's3', object: Result}|array{source: 'disk', path: string}|null
     */
    public function locate(FileGroupFile $file): ?array
    {
        $key = $file->getStoredName();
        if ($this->hasObject($key)) {
            return [
                'source' => 's3',
                'object' => $this->s3->getObject([
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                ]),
            ];
        }

        $path = $this->diskPath($file);
        if (is_file($path)) {
            return ['source' => 'disk', 'path' => $path];
        }

        return null;
    }

    /** Перезапись того же ключа (OnlyOffice save). Старый диск-only файл уезжает в MinIO. */
    public function replace(FileGroupFile $file, string $bytes): void
    {
        $key = $file->getStoredName();
        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $bytes,
            'ContentType' => self::mime($key),
        ]);

        $path = $this->diskPath($file);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Объекта/файла может уже не быть — строку всё равно удаляем.
     *
     * @param list<FileGroupFile> $files
     */
    public function deleteFiles(array $files, ?FileGroup $group = null): void
    {
        foreach ($files as $file) {
            $this->deleteFile($file);
        }
        if ($group !== null) {
            $dir = $this->directory($group);
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
    }

    public function deleteFile(FileGroupFile $file): void
    {
        $key = $file->getStoredName();
        try {
            $this->s3->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
        } catch (S3Exception) {
            // Уже нет или бакет недоступен — строку всё равно сносим.
        }

        $path = $this->diskPath($file);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Старый локальный путь (dual-read / миграция). */
    public function diskPath(FileGroupFile $file): string
    {
        return $this->directory($file->getGroup()) . '/' . $file->getStoredName();
    }

    public function directory(FileGroup $group): string
    {
        return rtrim($this->uploadDir, '/') . '/file_groups/' . $group->getId();
    }
}
