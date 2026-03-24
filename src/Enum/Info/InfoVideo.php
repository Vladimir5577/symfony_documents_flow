<?php

declare(strict_types=1);

namespace App\Enum\Info;

enum InfoVideo: string
{
    case CREATE_DOCUMENT = 'create_document.mp4';
    case INCOMING_DOCUMENT = 'incoming_document.mp4';
    case FILE_STORAGE = 'file_storage.mp4';
    case KANBAN = 'kanban.mp4';

    public function label(): string
    {
        return match ($this) {
            self::CREATE_DOCUMENT => 'Видео: Создание документа',
            self::INCOMING_DOCUMENT => 'Видео: Входящий документ',
            self::FILE_STORAGE => 'Видео: Файловое хранилище',
            self::KANBAN => 'Видео: Канбан',
        };
    }
}
