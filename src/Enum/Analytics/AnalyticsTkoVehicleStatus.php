<?php

declare(strict_types=1);

namespace App\Enum\Analytics;

enum AnalyticsTkoVehicleStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case WrittenOff = 'written_off';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'В работе',
            self::Inactive => 'Неактивна',
            self::WrittenOff => 'Списана',
        };
    }
}
