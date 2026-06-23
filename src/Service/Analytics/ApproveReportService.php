<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsReport;
use App\Entity\Organization\AbstractOrganization;
use App\Entity\User\User;
use App\Enum\Analytics\AnalyticsReportStatus;
use App\Repository\Analytics\AnalyticsReportRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ApproveReportService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnalyticsReportRepository $repository,
        private readonly FillReportValueService $fillService,
        private readonly RecalculateAggregatesService $recalculateService,
    ) {
    }

    /**
     * Получить все draft-отчёты (ожидают подтверждения).
     *
     * @return AnalyticsReport[]
     */
    public function findPendingReports(?AbstractOrganization $organization = null): array
    {
        $criteria = ['status' => AnalyticsReportStatus::Draft];
        if ($organization !== null) {
            $criteria['organization'] = $organization;
        }

        return $this->repository->findBy($criteria, ['createdAt' => 'DESC']);
    }

    /**
     * Получить draft-отчёты по набору организаций (иерархический доступ).
     *
     * @param int[] $organizationIds
     * @return AnalyticsReport[]
     */
    public function findPendingReportsByOrganizationIds(array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        return $this->repository->createQueryBuilder('r')
            ->join('r.organization', 'o')
            ->andWhere('r.status = :status')
            ->andWhere('o.id IN (:organizationIds)')
            ->setParameter('status', AnalyticsReportStatus::Draft)
            ->setParameter('organizationIds', $organizationIds)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findById(int $id, ?AbstractOrganization $organization = null): ?AnalyticsReport
    {
        $report = $this->repository->find($id);
        if (!$report) {
            return null;
        }

        if ($organization !== null && $report->getOrganization()?->getId() !== $organization->getId()) {
            return null;
        }

        return $report;
    }

    /**
     * @param int[] $organizationIds
     */
    public function findByIdForOrganizationIds(int $id, array $organizationIds): ?AnalyticsReport
    {
        if ($organizationIds === []) {
            return null;
        }

        return $this->repository->createQueryBuilder('r')
            ->join('r.organization', 'o')
            ->andWhere('r.id = :id')
            ->andWhere('o.id IN (:organizationIds)')
            ->setParameter('id', $id)
            ->setParameter('organizationIds', $organizationIds)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Подтвердить отчёт: draft -> confirmed.
     * Проверяет полноту по required-метрикам.
     */
    public function confirm(AnalyticsReport $report, User $approvedBy): void
    {
        if ($report->getStatus() !== AnalyticsReportStatus::Draft) {
            throw new \RuntimeException('Подтвердить можно только черновик.');
        }

        if (!$this->fillService->checkComplete($report)) {
            throw new \RuntimeException('Отчёт неполный и был сохранен как черновик: не все обязательные метрики заполнены.');
        }

        $report->setStatus(AnalyticsReportStatus::Confirmed);
        $report->setApprovedBy($approvedBy);
        $report->setApprovedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->recalculateService->recalculateForReport($report);
    }
}
