<?php

declare(strict_types=1);

namespace App\Enum\Kanban;

enum KanbanCardPriority: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';

    public function getLabel(): string
    {
        return match ($this) {
            self::LOW => 'Низкий',
            self::MEDIUM => 'Средний',
            self::HIGH => 'Высокий',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::LOW => 'success',
            self::MEDIUM => 'warning',
            self::HIGH => 'danger',
        };
    }

    public static function getChoices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->value] = $case->getLabel();
        }
        return $choices;
    }
}
