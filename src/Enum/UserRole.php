<?php

declare(strict_types=1);

namespace App\Enum;

enum UserRole: string
{
    case ROLE_ADMIN = 'ROLE_ADMIN';
    case ROLE_MANAGER_ANALYTIC = 'ROLE_MANAGER_ANALYTIC';
    case ROLE_MANAGER_HR = 'ROLE_MANAGER_HR';
    case ROLE_MANAGER_WORK_CITIZEN_APPEAL = 'ROLE_MANAGER_WORK_CITIZEN_APPEAL';
    case ROLE_MANAGER = 'ROLE_MANAGER';
    case ROLE_MODERATOR = 'ROLE_MODERATOR';
    case ROLE_WORK_CITIZEN_APPEAL = 'ROLE_WORK_CITIZEN_APPEAL';
    case ROLE_ANALYTIC = 'ROLE_ANALYTIC';
    case ROLE_HR = 'ROLE_HR';
    case ROLE_USER = 'ROLE_USER';

    public function getLabel(): string
    {
        return match ($this) {
            self::ROLE_ADMIN => 'Администратор',
            self::ROLE_MANAGER_ANALYTIC => 'Менеджер аналитик',
            self::ROLE_MANAGER_HR => 'Менеджер по кадрам',
            self::ROLE_MANAGER_WORK_CITIZEN_APPEAL => 'Менеджер по работе с обращениями граждан',
            self::ROLE_MANAGER => 'Менеджер',
            self::ROLE_MODERATOR => 'Роль модератор',
            self::ROLE_WORK_CITIZEN_APPEAL => 'Роль работа с обращениями граждан',
            self::ROLE_ANALYTIC => 'Роль заполнения аналитики',
            self::ROLE_HR => 'Роль кадровика',
            self::ROLE_USER => 'Роль пользователя',
        };
    }
}
