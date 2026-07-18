<?php

declare(strict_types=1);

namespace App\DTO\Document\Signature;

use App\Entity\Document\DocumentSignature;

/**
 * Результат проверки одной подписи документа.
 */
final readonly class SignatureVerificationResult
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public DocumentSignature $signature,
        public bool $valid,
        public ?string $reason = null,
        public array $details = [],
    ) {
    }
}
