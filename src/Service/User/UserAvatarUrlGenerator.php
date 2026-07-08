<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User\User;

final class UserAvatarUrlGenerator
{
    public const FILTER_THUMBNAIL = 'avatar_thumbnail';

    public const FILTER_MEDIUM = 'avatar_medium';

    public function __construct(
        private readonly string $imgproxyCacheBaseUrl,
        private readonly string $minioUserBucket,
    ) {
    }

    public function getAvatarUrl(User $user, string $filter = self::FILTER_MEDIUM): ?string
    {
        $storageKey = $user->getAvatarName();
        if ($storageKey === null || $storageKey === '') {
            return null;
        }

        [$width, $height] = match ($filter) {
            self::FILTER_THUMBNAIL => [50, 50],
            default => [200, 200],
        };

        return sprintf(
            '%s/unsafe/rs:fill:%d:%d/plain/s3://%s/%s',
            rtrim($this->imgproxyCacheBaseUrl, '/'),
            $width,
            $height,
            $this->minioUserBucket,
            $storageKey,
        );
    }
}
