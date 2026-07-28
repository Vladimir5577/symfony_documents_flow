<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

enum ReconciliationStatus: string
{
    case DRAFT = 'draft';
    case IN_PROGRESS = 'in_progress';
    case DONE = 'done';

    public function getLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::IN_PROGRESS => 'В работе',
            self::DONE => 'Завершена',
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
