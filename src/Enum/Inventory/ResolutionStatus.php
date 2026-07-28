<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

enum ResolutionStatus: string
{
    case NONE = 'none';
    case TO_CLARIFY = 'to_clarify';
    case EXPLAINED = 'explained';

    public function getLabel(): string
    {
        return match ($this) {
            self::NONE => 'Не обработано',
            self::TO_CLARIFY => 'Требует уточнения',
            self::EXPLAINED => 'Объяснено',
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
