<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User\User;
use App\Service\ImgproxyUrlSigner;

final class UserAvatarUrlGenerator
{
    public const FILTER_THUMBNAIL = 'avatar_thumbnail';

    public const FILTER_MEDIUM = 'avatar_medium';

    public function __construct(
        private readonly string $imgproxyCacheBaseUrl,
        private readonly string $minioUserBucket,
        private readonly ImgproxyUrlSigner $imgproxyUrlSigner,
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

        $source = sprintf('s3://%s/%s', $this->minioUserBucket, $storageKey);

        return rtrim($this->imgproxyCacheBaseUrl, '/') . '/'
            . $this->imgproxyUrlSigner->signSource(sprintf('rs:fill:%d:%d', $width, $height), $source);
    }
}
