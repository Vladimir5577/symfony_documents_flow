<?php

declare(strict_types=1);

namespace App\Enum\Analytics;

enum AnalyticsPeriodType: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Ежедневно',
            self::Weekly => 'Еженедельно',
            self::Monthly => 'Ежемесячно',
        };
    }
}
