<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Post;

use App\Entity\Post\File as PostFile;
use App\Entity\Post\Post;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;

/**
 * Генерация URL превью (400×400) для изображений постов через LiipImagine.
 * Аналог App\Service\Kanban\KanbanAttachmentPreviewUrlGenerator.
 *
 * storageKey — путь относительно data_root лоадера post_uploads
 * (%private_upload_dir_posts%), т.е. {postId}/{имя файла}.
 */
final class PostImagePreviewUrlGenerator
{
    private const FILTER = 'post_image_preview';
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly CacheManager $imagineCacheManager,
    ) {
    }

    public function getCoverPreviewUrl(Post $post): ?string
    {
        $name = $post->getCoverImageName();
        if ($name === null || $name === '' || !$this->isImageName($name)) {
            return null;
        }

        return $this->buildPreviewUrl($this->buildStorageKey($post->getId(), $name));
    }

    public function getFilePreviewUrl(PostFile $file): ?string
    {
        $filePath = $file->getFilePath();
        if ($filePath === null || $filePath === '' || !$this->isImageName($filePath)) {
            return null;
        }

        $postId = $file->getPost()?->getId();
        $storageKey = str_contains($filePath, '/')
            ? $filePath
            : $this->buildStorageKey($postId, $filePath);

        return $this->buildPreviewUrl($storageKey);
    }

    public function isImageName(string $name): bool
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return in_array($ext, self::IMAGE_EXTENSIONS, true);
    }

    private function buildStorageKey(?int $postId, string $name): string
    {
        return $postId . '/' . $name;
    }

    private function buildPreviewUrl(string $storageKey): string
    {
        return $this->toRelativePath(
            $this->imagineCacheManager->getBrowserPath($storageKey, self::FILTER),
        );
    }

    private function toRelativePath(string $url): string
    {
        // Keep URLs same-origin on the client to avoid CORS caused by absolute host:port generation.
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $parts = parse_url($url);
            $path = $parts['path'] ?? '/';
            $query = isset($parts['query']) ? '?' . $parts['query'] : '';
            $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

            return $path . $query . $fragment;
        }

        return $url;
    }
}
