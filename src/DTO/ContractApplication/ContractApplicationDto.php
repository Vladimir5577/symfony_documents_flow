<?php

declare(strict_types=1);

namespace App\DTO\ContractApplication;

final readonly class ContractApplicationDto
{
    /**
     * @param ContractApplicationFileDto[] $files
     * @param array<string, mixed>|null    $consumer
     * @param array<string, mixed>|null    $requisites
     * @param array<string, mixed>|null    $signer
     * @param array<string, mixed>|null    $waste
     * @param array<string, mixed>|null    $site
     * @param array<string, mixed>|null    $containers
     * @param array<string, mixed>|null    $extra
     */
    public function __construct(
        public int $id,
        public string $publicId,
        public string $consumerType,
        public string $consumerName,
        public ?string $organization,
        public ?string $primaryPhone,
        public ?string $primaryEmail,
        public string $status,
        public ?string $adminComment,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?string $ipAddress = null,
        public ?array $consumer = null,
        public ?array $requisites = null,
        public ?array $signer = null,
        public ?array $waste = null,
        public ?array $site = null,
        public ?array $containers = null,
        public ?array $extra = null,
        public array $files = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $consumerType = (string) ($data['consumerType'] ?? $data['consumer']['type'] ?? '');
        $consumerName = (string) ($data['consumerName'] ?? $data['consumer']['name'] ?? '');
        $organization = $data['organization'] ?? $data['consumer']['organization'] ?? null;
        $primaryPhone = $data['primaryPhone'] ?? $data['consumer']['primaryPhone'] ?? null;
        $primaryEmail = $data['primaryEmail'] ?? $data['consumer']['primaryEmail'] ?? null;

        return new self(
            id: (int) $data['id'],
            publicId: (string) $data['publicId'],
            consumerType: $consumerType,
            consumerName: $consumerName,
            organization: $organization !== null ? (string) $organization : null,
            primaryPhone: $primaryPhone !== null ? (string) $primaryPhone : null,
            primaryEmail: $primaryEmail !== null ? (string) $primaryEmail : null,
            status: (string) $data['status'],
            adminComment: $data['adminComment'] ?? null,
            createdAt: new \DateTimeImmutable((string) $data['createdAt']),
            updatedAt: new \DateTimeImmutable((string) $data['updatedAt']),
            ipAddress: $data['ipAddress'] ?? null,
            consumer: $data['consumer'] ?? null,
            requisites: $data['requisites'] ?? null,
            signer: $data['signer'] ?? null,
            waste: $data['waste'] ?? null,
            site: $data['site'] ?? null,
            containers: $data['containers'] ?? null,
            extra: $data['extra'] ?? null,
            files: array_map(
                static fn(array $f) => ContractApplicationFileDto::fromArray($f),
                $data['files'] ?? [],
            ),
        );
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'new'           => 'Новая',
            'in_review'     => 'На проверке',
            'contract_sent' => 'Договор отправлен',
            'signed'        => 'Подписан',
            'rejected'      => 'Отклонена',
            default         => $this->status,
        };
    }

    public function getConsumerTypeLabel(): string
    {
        return match ($this->consumerType) {
            'legal'  => 'Юридическое лицо',
            'ip'     => 'ИП',
            'person' => 'Физическое лицо',
            default  => $this->consumerType,
        };
    }
}
