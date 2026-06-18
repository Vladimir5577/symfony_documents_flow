<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User\User;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;

final class UserAvatarUrlGenerator
{
    public const FILTER_THUMBNAIL = 'avatar_thumbnail';

    public const FILTER_MEDIUM = 'avatar_medium';

    public function __construct(
        private readonly CacheManager $imagineCacheManager,
    ) {
    }

    public function getAvatarUrl(User $user, string $filter = self::FILTER_MEDIUM): ?string
    {
        $avatarName = $user->getAvatarName();
        $userId = $user->getId();
        if ($avatarName === null || $avatarName === '' || $userId === null) {
            return null;
        }

        $storageKey = $userId . '/' . $avatarName;
        $browserPath = $this->imagineCacheManager->getBrowserPath($storageKey, $filter);

        return $this->toRelativePath($browserPath);
    }

    private function toRelativePath(string $url): string
    {
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

