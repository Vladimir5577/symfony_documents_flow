<?php

declare(strict_types=1);

namespace App\Enum\Kanban;

enum KanbanSubtaskStatus: string
{
    case TO_DO = 'to_do';
    case IN_PROGRESS = 'in_progress';
    case DONE = 'done';

    public function getLabel(): string
    {
        return match ($this) {
            self::TO_DO => 'К выполнению',
            self::IN_PROGRESS => 'В работе',
            self::DONE => 'Выполнено',
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
