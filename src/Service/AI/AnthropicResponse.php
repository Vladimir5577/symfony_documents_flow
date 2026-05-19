<?php

declare(strict_types=1);

namespace App\Service\AI;

final readonly class AnthropicResponse
{
    public function __construct(
        public string $text,
        public ?int $tokensIn,
        public ?int $tokensOut,
        public string $model,
    ) {
    }
}
