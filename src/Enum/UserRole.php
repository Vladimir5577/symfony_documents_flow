<?php

declare(strict_types=1);

namespace App\Enum;

enum UserRole: string
{
    case ROLE_ADMIN = 'ROLE_ADMIN';
    case ROLE_CEO = 'ROLE_CEO';
    case ROLE_HR = 'ROLE_HR';
    case ROLE_EDITOR = 'ROLE_EDITOR';
    case ROLE_USER = 'ROLE_USER';

    public function getLabel(): string
    {
        return match ($this) {
            self::ROLE_ADMIN => 'Администратор',
            self::ROLE_CEO => 'Директор',
            self::ROLE_HR => 'Отдел кадров',
            self::ROLE_EDITOR => 'Редактор',
            self::ROLE_USER => 'Пользователь',
        };
    }
}
