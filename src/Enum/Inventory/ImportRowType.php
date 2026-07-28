<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

enum ImportRowType: string
{
    case NOMENCLATURE = 'nomenclature';
    case EMPLOYEE_HEADER = 'employee_header';
    case SUBDIVISION = 'subdivision';
    case TOTAL = 'total';
    case SKIP = 'skip';

    public function getLabel(): string
    {
        return match ($this) {
            self::NOMENCLATURE => 'Номенклатура',
            self::EMPLOYEE_HEADER => 'Заголовок сотрудника',
            self::SUBDIVISION => 'Подразделение',
            self::TOTAL => 'Итого',
            self::SKIP => 'Пропустить',
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
