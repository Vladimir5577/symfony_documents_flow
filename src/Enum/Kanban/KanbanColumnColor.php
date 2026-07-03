<?php

declare(strict_types=1);

namespace App\Enum\Kanban;

enum KanbanColumnColor: string
{
    case BG_PRIMARY = 'bg-primary';
    case BG_WARNING = 'bg-warning';
    case BG_SUCCESS = 'bg-success';
    case BG_DANGER = 'bg-danger';
    case BG_INFO = 'bg-info';
    case BG_DARK = 'bg-dark';
    case BG_CORAL = 'bg-coral';
    case BG_BRONZE = 'bg-bronze';
    case BG_LEMON = 'bg-lemon';
    case BG_OLIVE = 'bg-olive';
    case BG_SEA = 'bg-sea';
    case BG_PERIWINKLE = 'bg-periwinkle';
    case BG_LILAC = 'bg-lilac';
    case BG_GRAY = 'bg-gray';
    case BG_ORANGE = 'bg-orange';
    case BG_MAGENTA = 'bg-magenta';

    public function getLabel(): string
    {
        return match ($this) {
            self::BG_PRIMARY => 'Синий',
            self::BG_WARNING => 'Жёлтый',
            self::BG_SUCCESS => 'Зелёный',
            self::BG_DANGER => 'Красный',
            self::BG_INFO => 'Голубой',
            self::BG_DARK => 'Тёмный',
            self::BG_CORAL => 'Коралловый',
            self::BG_BRONZE => 'Бронзовый',
            self::BG_LEMON => 'Лимонный',
            self::BG_OLIVE => 'Оливковый',
            self::BG_SEA => 'Морская волна',
            self::BG_PERIWINKLE => 'Васильковый',
            self::BG_LILAC => 'Сиреневый',
            self::BG_GRAY => 'Серый',
            self::BG_ORANGE => 'Оранжевый',
            self::BG_MAGENTA => 'Пурпурный',
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
