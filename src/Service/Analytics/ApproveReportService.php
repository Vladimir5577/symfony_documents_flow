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
        private readonly RecalculateAggregatesService $recalculateService,
    ) {
    }

    /**
     * Получить все отчёты в статусе Submitted (на утверждение).
     * Если передана организация — только отчёты этой организации.
     *
     * @return AnalyticsReport[]
     */
    public function findPendingReports(?AbstractOrganization $organization = null): array
    {
        $criteria = ['status' => AnalyticsReportStatus::Submitted];
        if ($organization !== null) {
            $criteria['organization'] = $organization;
        }

        return $this->repository->findBy($criteria, ['createdAt' => 'DESC']);
    }

    /**
     * Получить все Submitted-отчёты по набору организаций (иерархический доступ).
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
            ->setParameter('status', AnalyticsReportStatus::Submitted)
            ->setParameter('organizationIds', $organizationIds)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Найти отчёт по ID.
     */
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
     * Найти отчёт по ID в пределах набора организаций (иерархический доступ).
     *
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
