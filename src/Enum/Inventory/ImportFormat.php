<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

enum ImportFormat: string
{
    case STOCK_SHEET = 'stock_sheet';
    case EMPLOYEE_SHEET = 'employee_sheet';

    public function getLabel(): string
    {
        return match ($this) {
            self::STOCK_SHEET => 'Оборотно-сальдовая ведомость',
            self::EMPLOYEE_SHEET => 'Материалы на сотрудниках',
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
