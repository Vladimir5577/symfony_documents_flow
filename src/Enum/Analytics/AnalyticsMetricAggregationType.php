<?php

declare(strict_types=1);

namespace App\Enum\Analytics;

enum AnalyticsMetricAggregationType: string
{
    case Sum = 'sum';
    case Avg = 'avg';
    case Min = 'min';
    case Max = 'max';
    case Last = 'last';
}
