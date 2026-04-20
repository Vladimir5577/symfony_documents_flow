<?php

declare(strict_types=1);

namespace App\Enum;

enum NotificationType: string
{
    case DOCUMENT_SENT = 'DOCUMENT_SENT';
    case NEW_INCOMING_DOCUMENT = 'NEW_INCOMING_DOCUMENT';
    case KANBAN_TASK_ASSIGNED_TO_USER = 'KANBAN_TASK_ASSIGNED_TO_USER';
    case KANBAN_CARD_CREATED = 'KANBAN_CARD_CREATED';
    case USER_ADDED_TO_KANBAN_PROJECT = 'USER_ADDED_TO_KANBAN_PROJECT';
    case USER_REMOVED_FROM_KANBAN_PROJECT = 'USER_REMOVED_FROM_KANBAN_PROJECT';
    /** @deprecated Use KANBAN_TASK_ASSIGNED_TO_USER. Kept for existing DB records. */
    case TASK_ASSIGNED = 'TASK_ASSIGNED';
    case TASK_MOVED = 'TASK_MOVED';
    case TASK_COMMENT_ADDED = 'TASK_COMMENT_ADDED';
    case GENERIC = 'GENERIC';

    public function getLabel(): string
    {
        return match ($this) {
            self::DOCUMENT_SENT => 'Документ отправлен',
            self::NEW_INCOMING_DOCUMENT => 'Новый входящий документ',
            self::KANBAN_TASK_ASSIGNED_TO_USER => 'Назначена задача',
            self::KANBAN_CARD_CREATED => 'Создана задача',
            self::USER_ADDED_TO_KANBAN_PROJECT => 'Добавлен в проект',
            self::USER_REMOVED_FROM_KANBAN_PROJECT => 'Исключён из проекта',
            self::TASK_ASSIGNED => 'Назначена задача',
            self::TASK_MOVED => 'Задача перемещена',
            self::TASK_COMMENT_ADDED => 'Новый комментарий в задаче',
            self::GENERIC => 'Уведомление',
        };
    }

    /**
     * Варианты для выбора в формах.
     * @return array<string, string> [value => label]
     */
    public static function getChoices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->value] = $case->getLabel();
        }

        return $choices;
    }
}

