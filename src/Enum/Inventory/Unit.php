<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

enum Unit: string
{
    case PCS = 'PCS';
    case SET = 'SET';
    case PACK = 'PACK';
    case METER = 'METER';
    case KG = 'KG';
    case LITER = 'LITER';
    case PAIR = 'PAIR';

    public function getLabel(): string
    {
        return match ($this) {
            self::PCS => 'шт',
            self::SET => 'компл',
            self::PACK => 'упак',
            self::METER => 'м',
            self::KG => 'кг',
            self::LITER => 'л',
            self::PAIR => 'пара',
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
