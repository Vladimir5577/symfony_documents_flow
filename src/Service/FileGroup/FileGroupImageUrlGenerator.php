<?php

declare(strict_types=1);

namespace App\Service\FileGroup;

/**
 * Превью картинок группы через imgproxy. Карточки ~16–32px — rs:fit:96:96 хватает.
 * ponytail: URL без проверки hasObject; нет объекта — imgproxy отдаст ошибку.
 */
final class FileGroupImageUrlGenerator
{
    public function __construct(
        private readonly string $imgproxyCacheBaseUrl,
        private readonly string $bucket,
    ) {
    }

    public function previewUrl(string $originalName, string $storageKey): ?string
    {
        $ext = FileGroupStorage::extension($originalName);
        if (!isset(FileGroupStorage::IMAGES[$ext]) || $storageKey === '') {
            return null;
        }

        return sprintf(
            '%s/unsafe/rs:fit:96:96/plain/s3://%s/%s',
            rtrim($this->imgproxyCacheBaseUrl, '/'),
            $this->bucket,
            $storageKey,
        );
    }
}
