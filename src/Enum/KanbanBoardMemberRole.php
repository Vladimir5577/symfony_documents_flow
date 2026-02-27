<?php

declare(strict_types=1);

namespace App\Enum;

enum KanbanBoardMemberRole: string
{
    case ADMIN = 'ADMIN';
    case EDITOR = 'EDITOR';
    case VIEWER = 'VIEWER';

    public function getLabel(): string
    {
        return match ($this) {
            self::ADMIN => 'Администратор',
            self::EDITOR => 'Редактор',
            self::VIEWER => 'Наблюдатель',
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
