<?php

declare(strict_types=1);

namespace App\Enum\Analytics;

enum AnalyticsMetricCategory: string
{
    case Finance = 'finance';
    case Mechanics = 'mechanics';
    case Hr = 'hr';
    case Tko = 'tko';
    case CitizenAppeal = 'citizen_appeal';

    public function label(): string
    {
        return match ($this) {
            self::Finance => 'Финансы',
            self::Mechanics => 'Механики',
            self::Hr => 'Отдел кадров',
            self::Tko => 'ТКО',
            self::CitizenAppeal => 'Обращение граждан',
        };
    }
}
