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

        return $this->imagineCacheManager->getBrowserPath($key, 'kanban_attachment_preview');
    }
}
