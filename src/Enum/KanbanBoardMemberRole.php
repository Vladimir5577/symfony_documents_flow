<?php

declare(strict_types=1);

namespace App\Enum;

enum KanbanBoardMemberRole: string
{
    case KANBAN_ADMIN = 'KANBAN_ADMIN';
    case KANAN_EDITOR = 'KANBAN_EDITOR';

    case KANBAN_VIEWER = 'KANBAN_VIEWER';

    public function getLabel(): string
    {
        return match ($this) {
            self::KANBAN_ADMIN => 'Администратор канбан',
            self::KANAN_EDITOR => 'Редактор канбан',
            self::KANBAN_VIEWER => 'Наблюдатель канбан',
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
