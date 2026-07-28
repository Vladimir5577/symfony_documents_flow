<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

enum ImportRowStatus: string
{
    case READY = 'ready';
    case UNMATCHED = 'unmatched';
    case REJECTED = 'rejected';
    case APPLIED = 'applied';

    public function getLabel(): string
    {
        return match ($this) {
            self::READY => 'Готова',
            self::UNMATCHED => 'Не сопоставлена',
            self::REJECTED => 'Отклонена',
            self::APPLIED => 'Применена',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function getChoices(): array
    {
        return array_column(
            array_map(static fn (self $case): array => [$case->value, $case->getLabel()], self::cases()),
            1,
            0,
        );
    }
}
