<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

enum ImportBatchStatus: string
{
    case PARSED = 'parsed';
    case PARTIALLY_APPLIED = 'partially_applied';
    case APPLIED = 'applied';
    case FAILED = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::PARSED => 'Разобран',
            self::PARTIALLY_APPLIED => 'Применён частично',
            self::APPLIED => 'Применён',
            self::FAILED => 'Ошибка',
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
