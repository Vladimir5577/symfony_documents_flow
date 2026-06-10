<?php

declare(strict_types=1);

namespace App\Enum;

enum UserRole: string
{
    case ROLE_ADMIN = 'ROLE_ADMIN';
    case ROLE_MANAGER = 'ROLE_MANAGER';
    case ROLE_CITIZEN_APPEAL = 'ROLE_CITIZEN_APPEAL';
    case ROLE_HR = 'ROLE_HR';
    case ROLE_CONTRACT_APPLICATION = 'ROLE_CONTRACT_APPLICATION';
    case ROLE_TKO = 'ROLE_TKO';
    case ROLE_USER = 'ROLE_USER';

    public function getLabel(): string
    {
        return match ($this) {
            self::ROLE_ADMIN => 'Администратор',
            self::ROLE_MANAGER => 'Менеджер',
            self::ROLE_CITIZEN_APPEAL => 'Роль работа с обращениями граждан',
            self::ROLE_HR => 'Роль кадровика',
            self::ROLE_CONTRACT_APPLICATION => 'Роль заявок на договор',
            self::ROLE_TKO => 'Роль аналитики ТКО',
            self::ROLE_USER => 'Роль пользователя',
        };
    }
}
