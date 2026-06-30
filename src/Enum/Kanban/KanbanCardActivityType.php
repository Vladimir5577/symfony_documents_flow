<?php

declare(strict_types=1);

namespace App\Enum\Kanban;

enum KanbanCardActivityType: string
{
    case CREATED = 'created';
    case MOVED = 'moved';
    case RENAMED = 'renamed';
    case DESCRIPTION_CHANGED = 'description_changed';
    case PRIORITY_CHANGED = 'priority_changed';
    case DUE_DATE_CHANGED = 'due_date_changed';
    case ASSIGNEE_ADDED = 'assignee_added';
    case ASSIGNEE_REMOVED = 'assignee_removed';
    case LABEL_ADDED = 'label_added';
    case LABEL_REMOVED = 'label_removed';
    case COLOR_CHANGED = 'color_changed';
    case COMMENT_ADDED = 'comment_added';
    case ATTACHMENT_ADDED = 'attachment_added';
    case ATTACHMENT_REMOVED = 'attachment_removed';
    case SUBTASK_ADDED = 'subtask_added';
    case SUBTASK_COMPLETED = 'subtask_completed';
    case SUBTASK_REOPENED = 'subtask_reopened';
    case SUBTASK_REMOVED = 'subtask_removed';
    case SUBTASK_ASSIGNED = 'subtask_assigned';
    case SUBTASK_UNASSIGNED = 'subtask_unassigned';
    case ARCHIVED = 'archived';
    case RESTORED = 'restored';
    case COMPLETED = 'completed';
    case REOPENED = 'reopened';

    public function getLabel(): string
    {
        return match ($this) {
            self::CREATED => 'Задача создана',
            self::MOVED => 'Перемещена в другой столбец',
            self::RENAMED => 'Изменено название',
            self::DESCRIPTION_CHANGED => 'Изменено описание',
            self::PRIORITY_CHANGED => 'Изменён приоритет',
            self::DUE_DATE_CHANGED => 'Изменён срок',
            self::ASSIGNEE_ADDED => 'Назначен исполнитель',
            self::ASSIGNEE_REMOVED => 'Снят исполнитель',
            self::LABEL_ADDED => 'Добавлена метка',
            self::LABEL_REMOVED => 'Удалена метка',
            self::COLOR_CHANGED => 'Изменён цвет',
            self::COMMENT_ADDED => 'Добавлен комментарий',
            self::ATTACHMENT_ADDED => 'Добавлено вложение',
            self::ATTACHMENT_REMOVED => 'Удалено вложение',
            self::SUBTASK_ADDED => 'Добавлена подзадача',
            self::SUBTASK_COMPLETED => 'Подзадача выполнена',
            self::SUBTASK_REOPENED => 'Подзадача снова открыта',
            self::SUBTASK_REMOVED => 'Удалена подзадача',
            self::SUBTASK_ASSIGNED => 'Назначен исполнитель подзадачи',
            self::SUBTASK_UNASSIGNED => 'Снят исполнитель подзадачи',
            self::ARCHIVED => 'Задача архивирована',
            self::RESTORED => 'Задача восстановлена из архива',
            self::COMPLETED => 'Задача выполнена',
            self::REOPENED => 'Задача снова открыта',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::CREATED => 'bi-plus-circle',
            self::MOVED => 'bi-arrow-left-right',
            self::RENAMED => 'bi-pencil',
            self::DESCRIPTION_CHANGED => 'bi-card-text',
            self::PRIORITY_CHANGED => 'bi-flag',
            self::DUE_DATE_CHANGED => 'bi-calendar-event',
            self::ASSIGNEE_ADDED => 'bi-person-plus',
            self::ASSIGNEE_REMOVED => 'bi-person-dash',
            self::LABEL_ADDED => 'bi-tag',
            self::LABEL_REMOVED => 'bi-tag',
            self::COLOR_CHANGED => 'bi-palette',
            self::COMMENT_ADDED => 'bi-chat-left-text',
            self::ATTACHMENT_ADDED => 'bi-paperclip',
            self::ATTACHMENT_REMOVED => 'bi-paperclip',
            self::SUBTASK_ADDED => 'bi-list-check',
            self::SUBTASK_COMPLETED => 'bi-check2-square',
            self::SUBTASK_REOPENED => 'bi-square',
            self::SUBTASK_REMOVED => 'bi-list-check',
            self::SUBTASK_ASSIGNED => 'bi-person-plus',
            self::SUBTASK_UNASSIGNED => 'bi-person-dash',
            self::ARCHIVED => 'bi-archive',
            self::RESTORED => 'bi-arrow-counterclockwise',
            self::COMPLETED => 'bi-check-circle',
            self::REOPENED => 'bi-arrow-counterclockwise',
        };
    }
}
