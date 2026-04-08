<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsReport;
use App\Entity\User;
use App\Enum\Analytics\AnalyticsReportStatus;
use App\Repository\Analytics\AnalyticsReportRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ApproveReportService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnalyticsReportRepository $repository,
        private readonly RecalculateAggregatesService $recalculateService,
    ) {
    }

    /**
     * Получить все отчёты в статусе Submitted (на утверждение).
     * @return AnalyticsReport[]
     */
    public function findPendingReports(): array
    {
        return $this->repository->findBy(
            ['status' => AnalyticsReportStatus::Submitted],
            ['createdAt' => 'DESC']
        );
    }

    /**
     * Найти отчёт по ID.
     */
    public function findById(int $id): ?AnalyticsReport
    {
        return $this->repository->find($id);
    }

    /**
     * Утвердить отчёт: Submitted -> Approved, записываем approvedAt и approvedBy.
     */
    public function approve(AnalyticsReport $report, User $approvedBy): void
    {
        if ($report->getStatus() !== AnalyticsReportStatus::Submitted) {
            throw new \RuntimeException('Утвердить можно только отчёт в статусе "Отправлен".');
        }

        $report->setStatus(AnalyticsReportStatus::Approved);
        $report->setApprovedAt(new \DateTimeImmutable());
        $report->setApprovedBy($approvedBy);
        $this->em->flush();

        // Автоматический пересчёт агрегатов после утверждения
        $this->recalculateService->recalculateForReport($report);
    }
}
