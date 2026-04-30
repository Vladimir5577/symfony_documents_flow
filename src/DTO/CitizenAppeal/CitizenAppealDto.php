<?php

declare(strict_types=1);

namespace App\DTO\CitizenAppeal;

final readonly class CitizenAppealDto
{
    /**
     * @param CitizenAppealFileDto[] $files
     */
    public function __construct(
        public int $id,
        public string $publicId,
        public string $fio,
        public ?string $phone,
        public ?string $email,
        public string $appealType,
        public string $city,
        public string $address,
        public ?string $message,
        public string $replyTo,
        public string $status,
        public ?string $adminComment,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public array $files = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            publicId: (string) $data['publicId'],
            fio: (string) $data['fio'],
            phone: $data['phone'] ?? null,
            email: $data['email'] ?? null,
            appealType: (string) $data['appealType'],
            city: (string) $data['city'],
            address: (string) $data['address'],
            message: $data['message'] ?? null,
            replyTo: (string) $data['replyTo'],
            status: (string) $data['status'],
            adminComment: $data['adminComment'] ?? null,
            createdAt: new \DateTimeImmutable((string) $data['createdAt']),
            updatedAt: new \DateTimeImmutable((string) $data['updatedAt']),
            files: array_map(
                static fn(array $f) => CitizenAppealFileDto::fromArray($f),
                $data['files'] ?? [],
            ),
        );
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'new'         => 'Новое',
            'in_progress' => 'В работе',
            'done'        => 'Обработано',
            default       => $this->status,
        };
    }

    public function getAppealTypeLabel(): string
    {
        return match ($this->appealType) {
            'individual_contract'        => 'Договор (физ. лицо)',
            'legal_contract'             => 'Договор (юр. лицо)',
            'receipt_data_correction'    => 'Корректировка квитанции',
            'recalculation'              => 'Перерасчёт',
            'waste_pickup_schedule'      => 'График вывоза мусора',
            'illegal_dump'               => 'Несанкционированная свалка',
            'bulky_waste'                => 'КГО',
            'container_site_improvement' => 'Контейнерная площадка',
            'other'                      => 'Другое',
            default                      => $this->appealType,
        };
    }

    public function getCityLabel(): string
    {
        return match ($this->city) {
            'donetsk'     => 'Донецк',
            'makeyevka'   => 'Макеевка',
            'mariupol'    => 'Мариуполь',
            'gorlovka'    => 'Горловка',
            'enakievo'    => 'Енакиево',
            'shakhtersk'  => 'Шахтёрск',
            'torez'       => 'Торез',
            'amvrosievka' => 'Амвросиевка',
            'yasinovataya'=> 'Ясиноватая',
            default       => $this->city,
        };
    }
}
