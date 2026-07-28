<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

enum DocumentStatus: string
{
    case DRAFT = 'draft';
    case POSTED = 'posted';
    case REVERSED = 'reversed';

    public function getLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::POSTED => 'Проведён',
            self::REVERSED => 'Сторнирован',
        };
    }

    public function isFinal(): bool
    {
        return $this !== self::DRAFT;
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
