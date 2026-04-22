<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsReport;
use App\Enum\Analytics\AnalyticsReportStatus;
use Doctrine\ORM\EntityManagerInterface;

final class SubmitReportService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FillReportValueService $fillService,
    ) {
    }

    /**
     * Перевести отчёт из draft в submitted.
     * Требует полноты (все required-метрики заполнены).
     */
    public function submit(AnalyticsReport $report): void
    {
        if ($report->getStatus()->value !== 'draft') {
            throw new \RuntimeException('Отправить можно только черновик.');
        }

        // Проверяем период не закрыт
        $period = $report->getPeriod();
        if ($period && $period->isClosed()) {
            throw new \RuntimeException('Период закрыт. Редактирование запрещено.');
        }

        // Проверяем полноту
        $isComplete = $this->fillService->checkComplete($report);
        $report->setIsComplete($isComplete);

        if (!$isComplete) {
            throw new \RuntimeException('Отчёт неполный: не все обязательные метрики заполнены.');
        }

        $report->setStatus(AnalyticsReportStatus::Submitted);
        $report->setSubmittedAt(new \DateTimeImmutable());
        $this->em->flush();
    }
}
