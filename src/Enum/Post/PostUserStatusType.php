<?php

namespace App\Enum\Post;

enum PostUserStatusType: int
{
    case ACKNOWLEDGED = 1;
    case REJECTED = 2;
    case LATER = 3;

    public function label(): string
    {
        return match ($this) {
            self::ACKNOWLEDGED => 'Ознакомлен',
            self::REJECTED => 'Отклонено',
            self::LATER => 'Ознакомлюсь позже',
        };
    }
}

