<?php

namespace App\Enum\User;

enum UserFileType: string
{
    case TEMPLATE = 'template';
    case DRAFT = 'draft';
    case IMPORTANT = 'important';
    case UNIMPORTANT = 'unimportant';

    public function label(): string
    {
        return match ($this) {
            self::TEMPLATE => 'Шаблон',
            self::DRAFT => 'Черновик',
            self::IMPORTANT => 'Важный документ',
            self::UNIMPORTANT => 'Неважный документ',
        };
    }
}
