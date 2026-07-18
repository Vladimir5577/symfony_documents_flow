<?php

declare(strict_types=1);

namespace App\Enum\User;

enum UserRole: string
{
    case ROLE_ADMIN = 'ROLE_ADMIN';
    case ROLE_ANALYTIC = 'ROLE_ANALYTIC';
    case ROLE_MANAGER = 'ROLE_MANAGER';
    case ROLE_CITIZEN_APPEAL = 'ROLE_CITIZEN_APPEAL';
    case ROLE_HR = 'ROLE_HR';
    case ROLE_CONTRACT_APPLICATION = 'ROLE_CONTRACT_APPLICATION';
    case ROLE_TKO = 'ROLE_TKO';
    case ROLE_FINANCE = 'ROLE_FINANCE';
    case ROLE_CLIENTS_DEPARTMENT = 'ROLE_CLIENTS_DEPARTMENT';
    case ROLE_MECHANIC = 'ROLE_MECHANIC';
    case ROLE_PURCHASE_DIRECTOR = 'ROLE_PURCHASE_DIRECTOR';
    case ROLE_PURCHASE_DEPARTMENT = 'ROLE_PURCHASE_DEPARTMENT';
    case ROLE_USER = 'ROLE_USER';

    public function getLabel(): string
    {
        return match ($this) {
            self::ROLE_ADMIN => 'Администратор',
            self::ROLE_ANALYTIC => 'Аналитик',
            self::ROLE_MANAGER => 'Менеджер',
            self::ROLE_CITIZEN_APPEAL => 'Роль работа с обращениями граждан',
            self::ROLE_HR => 'Роль кадровика',
            self::ROLE_CONTRACT_APPLICATION => 'Роль заявок на договор',
            self::ROLE_TKO => 'Роль аналитики ТКО',
            self::ROLE_FINANCE => 'Роль финансиста',
            self::ROLE_CLIENTS_DEPARTMENT => 'Роль абон отдела',
            self::ROLE_MECHANIC => 'Роль механика',
            self::ROLE_PURCHASE_DIRECTOR => 'Директор закупок',
            self::ROLE_PURCHASE_DEPARTMENT => 'Отдел закупок',
            self::ROLE_USER => 'Роль пользователя',
        };
    }
}
