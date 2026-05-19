<?php

declare(strict_types=1);

namespace App\Exception\AI;

final class RateLimitException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $retryAfter,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 429, $previous);
    }
}
