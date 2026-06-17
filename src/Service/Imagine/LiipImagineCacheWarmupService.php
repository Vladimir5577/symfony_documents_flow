<?php

declare(strict_types=1);

namespace App\Service\Imagine;

use Liip\ImagineBundle\Service\FilterService;

/**
 * Прогрев кеша LiipImagine вне /media/cache/ (API и т.п.) с тем же запасом памяти,
 * что и {@see \App\EventSubscriber\LiipImagineRequestMemorySubscriber}.
 */
final class LiipImagineCacheWarmupService
{
    public const MEMORY_LIMIT = '512M';

    public const MAX_EXECUTION_TIME = '120';

    public function __construct(
        private readonly FilterService $filterService,
    ) {
    }

    public function warmUp(string $path, string $filter, ?string $resolver = null, bool $forced = false): bool
    {
        ini_set('memory_limit', self::MEMORY_LIMIT);
        ini_set('max_execution_time', self::MAX_EXECUTION_TIME);

        return $this->filterService->warmUpCache($path, $filter, $resolver, $forced);
    }
}
