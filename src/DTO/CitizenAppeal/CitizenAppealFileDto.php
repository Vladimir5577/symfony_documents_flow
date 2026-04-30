<?php

declare(strict_types=1);

namespace App\DTO\CitizenAppeal;

final readonly class CitizenAppealFileDto
{
    public function __construct(
        public int $id,
        public string $url,
        public string $originalName,
        public string $mimeType,
        public int $fileSize,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            url: (string) $data['url'],
            originalName: (string) $data['originalName'],
            mimeType: (string) $data['mimeType'],
            fileSize: (int) $data['fileSize'],
        );
    }

    public function getFileSizeFormatted(): string
    {
        $kb = $this->fileSize / 1024;
        if ($kb < 1024) {
            return round($kb, 1) . ' КБ';
        }
        return round($kb / 1024, 1) . ' МБ';
    }
}
