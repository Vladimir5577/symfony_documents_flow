<?php

declare(strict_types=1);

namespace App\Enum\Analytics;

enum AnalyticsReportStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
}
