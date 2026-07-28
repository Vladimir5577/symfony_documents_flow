<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

enum ComparisonStatus: string
{
    case OK = 'ok';
    case QTY_MISMATCH = 'qty_mismatch';
    case MISSING_IN_SYSTEM = 'missing_in_system';
    case MISSING_IN_1C = 'missing_in_1c';

    public function getLabel(): string
    {
        return match ($this) {
            self::OK => 'Совпадает',
            self::QTY_MISMATCH => 'Расхождение количества',
            self::MISSING_IN_SYSTEM => 'Отсутствует в системе',
            self::MISSING_IN_1C => 'Отсутствует в 1С',
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
