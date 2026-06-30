<?php

namespace App\Service\Kanban;

use App\Entity\Kanban\KanbanAttachment;

/**
 * Генерирует URL превью картинок через imgproxy (файловое хранилище file_storage).
 *
 * imgproxy берёт исходник из MinIO (S3) и ресайзит на лету.
 * nginx-кэш перед imgproxy хранит готовые превью на диске (замена LiipImagine).
 *
 * Поток: браузер → nginx-кэш (:8082) → imgproxy → MinIO
 */
final class KanbanAttachmentPreviewUrlGenerator
{
    public function __construct(
        private readonly string $imgproxyCacheBaseUrl,
        private readonly string $minioBucket,
    ) {
    }

    public function isImage(KanbanAttachment $attachment): bool
    {
        $ct = $attachment->getContentType() ?? '';

        return str_starts_with($ct, 'image/');
    }

    public function getPreviewUrl(KanbanAttachment $attachment): ?string
    {
        if (!$this->isImage($attachment)) {
            return null;
        }

        $key = $attachment->getStorageKey();
        if ($key === null || $key === '') {
            return null;
        }

        // imgproxy unsafe URL (локальная разработка; для прода — подписанные URL).
        // Формат: /unsafe/{processing}/{source_url}
        return sprintf(
            '%s/unsafe/rs:fit:400:400/plain/s3://%s/%s',
            rtrim($this->imgproxyCacheBaseUrl, '/'),
            $this->minioBucket,
            $key,
        );
    }
}
