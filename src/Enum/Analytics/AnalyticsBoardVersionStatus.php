<?php

declare(strict_types=1);

namespace App\Enum\Analytics;

enum AnalyticsBoardVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
