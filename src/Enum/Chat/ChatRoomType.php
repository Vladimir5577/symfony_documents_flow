<?php

namespace App\Enum\Chat;

enum ChatRoomType: string
{
    case DEPARTMENT = 'department';
    case PRIVATE = 'private';

    public function label(): string
    {
        return match ($this) {
            self::DEPARTMENT => 'Отдел',
            self::PRIVATE => 'Приватный',
        };
    }
}
