<?php

namespace App\Enum\Post;

enum PostType: string
{
    case ORDER = 'order';
    case NEWS = 'news';
    case INSTRUCTION = 'instruction';
    case REGULATION = 'regulation';
    case DISPOSITION = 'disposition';

    public function label(): string
    {
        return match ($this) {
            self::ORDER => 'Приказ',
            self::NEWS => 'Новость',
            self::INSTRUCTION => 'Инструкция',
            self::REGULATION => 'Положение',
            self::DISPOSITION => 'Распоряжение',
        };
    }
}
