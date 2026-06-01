<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsPeriod;
use App\Entity\Analytics\AnalyticsReport;
use App\Entity\Organization\AbstractOrganization;

/**
 * Заглушка: логика агрегации временно удалена и будет переписана.
 * Подпись и публичный API сохранены, чтобы вызовы из ApproveReportService
 * и других мест не падали.
 */
final class RecalculateAggregatesService
{
    public function recalculateForReport(AnalyticsReport $report, bool $flush = true): void
    {
    }

    public function recalculateForScope(?AnalyticsPeriod $period, ?AbstractOrganization $organization): int
    {
        return 0;
    }

    public function recalculateAll(): int
    {
        return 0;
    }
}
