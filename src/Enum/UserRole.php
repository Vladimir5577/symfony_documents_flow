<?php

declare(strict_types=1);

namespace App\Enum;

enum UserRole: string
{
    case ROLE_ADMIN = 'ROLE_ADMIN';
    case ROLE_MANAGER_ANALYTIC = 'ROLE_MANAGER_ANALYTIC';
    case ROLE_MANAGER = 'ROLE_MANAGER';
    case ROLE_MODERATOR = 'ROLE_MODERATOR';
    case ROLE_USER = 'ROLE_USER';

    public function getLabel(): string
    {
        return match ($this) {
            self::ROLE_ADMIN => 'Администратор',
            self::ROLE_MANAGER_ANALYTIC => 'Аналитик',
            self::ROLE_MANAGER => 'Управленческая роль',
            self::ROLE_MODERATOR => 'Роль модератор',
            self::ROLE_USER => 'Роль пользователя',
        };
    }
}
