<?php

declare(strict_types=1);

namespace App\Service\FileGroup;

use App\Entity\FileGroup\FileGroup;
use App\Entity\FileGroup\FileGroupFile;
use App\Entity\FileGroup\FileGroupFolder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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
    ) {
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

    public function path(FileGroupFile $file): string
    {
        return $this->directory($file->getGroup()) . '/' . $file->getStoredName();
    }

    /**
     * @param list<string> $paths
     */
    public function unlinkAll(array $paths, ?string $directory = null): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if ($directory !== null && is_dir($directory)) {
            @rmdir($directory);
        }
    }

    public function directory(FileGroup $group): string
    {
        return rtrim($this->uploadDir, '/') . '/file_groups/' . $group->getId();
    }

    public function store(FileGroup $group, UploadedFile $upload, string $originalName, ?FileGroupFolder $folder): FileGroupFile
    {
        $size = (int) $upload->getSize();
        $ext = self::extension($originalName);
        if (!preg_match('/^[a-z0-9]{1,20}$/', $ext)) {
            $ext = '';
        }
        $stored = bin2hex(random_bytes(16)) . ($ext === '' ? '' : '.' . $ext);
        $dir = $this->directory($group);
        // ponytail: /uploads/file_groups, не var/ проекта — www-data не создаёт каталоги в bind-mount. В MinIO, когда понадобятся превью.
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('file_group_dir');
        }
        $upload->move($dir, $stored);

        return new FileGroupFile($group, $originalName, $stored, $size, $folder);
    }
}
