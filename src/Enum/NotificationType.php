<?php

declare(strict_types=1);

namespace App\Enum;

enum NotificationType: string
{
    case DOCUMENT_SENT = 'DOCUMENT_SENT';
    case NEW_INCOMING_DOCUMENT = 'NEW_INCOMING_DOCUMENT';
    case TASK_ASSIGNED = 'TASK_ASSIGNED';
    case TASK_MOVED = 'TASK_MOVED';
    case TASK_COMMENT_ADDED = 'TASK_COMMENT_ADDED';
    case GENERIC = 'GENERIC';

    public function getLabel(): string
    {
        return match ($this) {
            self::DOCUMENT_SENT => 'Документ отправлен',
            self::NEW_INCOMING_DOCUMENT => 'Новый входящий документ',
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

