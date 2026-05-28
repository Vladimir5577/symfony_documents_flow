<?php

declare(strict_types=1);

namespace App\Enum\Analytics;

enum AnalyticsCategory: string
{
    case Other = 'other';
    case Finance = 'finance';
    case Mechanics = 'mechanics';
    case Hr = 'hr';
    case Tko = 'tko';
    case CitizenAppeal = 'citizen_appeal';

    public function label(): string
    {
        return match ($this) {
            self::Other => 'Прочее',
            self::Finance => 'Финансы',
            self::Mechanics => 'Механики',
            self::Hr => 'Отдел кадров',
            self::Tko => 'ТКО',
            self::CitizenAppeal => 'Обращение граждан',
        };
    }
}
