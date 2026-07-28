<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

enum DeviceStatus: string
{
    case IN_SERVICE = 'in_service';
    case IN_STORAGE = 'in_storage';
    case IN_REPAIR = 'in_repair';
    case WRITTEN_OFF = 'written_off';

    public function getLabel(): string
    {
        return match ($this) {
            self::IN_SERVICE => 'В эксплуатации',
            self::IN_STORAGE => 'На складе',
            self::IN_REPAIR => 'В ремонте',
            self::WRITTEN_OFF => 'Списано',
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
