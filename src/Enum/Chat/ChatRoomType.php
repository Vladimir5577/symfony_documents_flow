<?php

namespace App\Enum\Chat;

enum ChatRoomType: string
{
    case DEPARTMENT = 'department';
    case PRIVATE = 'private';
    case GROUP = 'group';

    public function label(): string
    {
        return match ($this) {
            self::DEPARTMENT => 'Отдел',
            self::PRIVATE => 'Приватный',
            self::GROUP => 'Групповой',
        };
    }
}
