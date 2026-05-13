<?php

declare(strict_types=1);

namespace App\DTO\Vacancy;

final readonly class VacancyDto
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $title,
        public ?string $salary,
        public string $cityValue,
        public string $cityLabel,
        public string $employmentTypeValue,
        public string $employmentTypeLabel,
        public string $scheduleValue,
        public string $scheduleLabel,
        public string $experienceValue,
        public string $experienceLabel,
        public ?string $shortDescription,
        public array $bodyBlocks,
        public ?string $contactEmail,
        public ?string $contactPhone,
        public bool $isPublished,
        public int $sortOrder,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            slug: (string) $data['slug'],
            title: (string) $data['title'],
            salary: $data['salary'] ?? null,
            cityValue: (string) $data['city']['value'],
            cityLabel: (string) $data['city']['label'],
            employmentTypeValue: (string) $data['employmentType']['value'],
            employmentTypeLabel: (string) $data['employmentType']['label'],
            scheduleValue: (string) $data['schedule']['value'],
            scheduleLabel: (string) $data['schedule']['label'],
            experienceValue: (string) $data['experience']['value'],
            experienceLabel: (string) $data['experience']['label'],
            shortDescription: $data['shortDescription'] ?? null,
            bodyBlocks: $data['bodyBlocks'] ?? [],
            contactEmail: $data['contactEmail'] ?? null,
            contactPhone: $data['contactPhone'] ?? null,
            isPublished: (bool) $data['isPublished'],
            sortOrder: (int) $data['sortOrder'],
            createdAt: new \DateTimeImmutable((string) $data['createdAt']),
            updatedAt: new \DateTimeImmutable((string) $data['updatedAt']),
        );
    }
}
