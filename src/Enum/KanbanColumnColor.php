<?php

declare(strict_types=1);

namespace App\Enum;

enum KanbanColumnColor: string
{
    case BG_PRIMARY = 'bg-primary';
    case BG_WARNING = 'bg-warning';
    case BG_SUCCESS = 'bg-success';
    case BG_DANGER = 'bg-danger';
    case BG_INFO = 'bg-info';
    case BG_DARK = 'bg-dark';

    public function getLabel(): string
    {
        return match ($this) {
            self::BG_PRIMARY => 'Синий',
            self::BG_WARNING => 'Жёлтый',
            self::BG_SUCCESS => 'Зелёный',
            self::BG_DANGER => 'Красный',
            self::BG_INFO => 'Голубой',
            self::BG_DARK => 'Тёмный',
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
