<?php

namespace App\Service\Kanban;

use App\Entity\Kanban\KanbanAttachment;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;

final class KanbanAttachmentPreviewUrlGenerator
{
    public function __construct(
        private readonly CacheManager $imagineCacheManager,
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

        $browserPath = $this->imagineCacheManager->getBrowserPath($key, 'kanban_attachment_preview');

        return $this->toRelativePath($browserPath);
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
