<?php

declare(strict_types=1);

namespace App\DTO\Document\Signature;

/**
 * Результат проверки всех подписей документа.
 */
final readonly class DocumentVerificationResult
{
    /**
     * @param SignatureVerificationResult[] $signatures
     */
    public function __construct(
        public ?string $actualHash,
        public array $signatures,
        public bool $allValid,
    ) {
    }
}
