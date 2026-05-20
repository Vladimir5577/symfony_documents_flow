<?php

declare(strict_types=1);

namespace App\DTO\VacancyApplication;

final readonly class VacancyApplicationResumeDto
{
    public function __construct(
        public string $originalName,
        public string $mimeType,
        public int $size,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            originalName: (string) $data['originalName'],
            mimeType: (string) $data['mimeType'],
            size: (int) $data['size'],
        );
    }

    public function getFileSizeFormatted(): string
    {
        $kb = $this->size / 1024;
        if ($kb < 1024) {
            return round($kb, 1) . ' КБ';
        }

        return round($kb / 1024, 1) . ' МБ';
    }
}
