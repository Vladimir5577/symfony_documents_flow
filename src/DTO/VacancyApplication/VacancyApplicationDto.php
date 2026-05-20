<?php

declare(strict_types=1);

namespace App\DTO\VacancyApplication;

final readonly class VacancyApplicationDto
{
    public function __construct(
        public int $id,
        public int $vacancyId,
        public string $vacancySlug,
        public string $vacancyTitleSnapshot,
        public string $fio,
        public string $phone,
        public string $email,
        public string $status,
        public string $statusLabel,
        public ?string $adminComment,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?string $coverLetter = null,
        public ?VacancyApplicationResumeDto $resume = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $status = (string) $data['status'];
        $resume = isset($data['resume']) && \is_array($data['resume'])
            ? VacancyApplicationResumeDto::fromArray($data['resume'])
            : null;

        return new self(
            id: (int) $data['id'],
            vacancyId: (int) $data['vacancyId'],
            vacancySlug: (string) $data['vacancySlug'],
            vacancyTitleSnapshot: (string) $data['vacancyTitleSnapshot'],
            fio: (string) $data['fio'],
            phone: (string) $data['phone'],
            email: (string) $data['email'],
            status: $status,
            statusLabel: self::STATUS_LABELS[$status] ?? $status,
            adminComment: $data['adminComment'] ?? null,
            createdAt: new \DateTimeImmutable((string) $data['createdAt']),
            updatedAt: new \DateTimeImmutable((string) $data['updatedAt']),
            coverLetter: $data['coverLetter'] ?? null,
            resume: $resume,
        );
    }

    public const STATUS_LABELS = [
        'new'      => 'Новый',
        'viewed'   => 'Просмотрен',
        'invited'  => 'Приглашён',
        'rejected' => 'Отказ',
        'archived' => 'Архив',
    ];
}
