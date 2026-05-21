<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsBoard;
use App\Entity\Analytics\AnalyticsPeriod;
use App\Entity\Analytics\AnalyticsReport;
use App\Entity\Analytics\AnalyticsReportValue;
use App\Entity\Organization\AbstractOrganization;
use App\Enum\Analytics\AnalyticsPeriodType;
use App\Enum\Analytics\AnalyticsReportStatus;
use App\Repository\Analytics\AnalyticsReportRepository;
use Doctrine\ORM\EntityManagerInterface;

final class CreateReportService
{
    private const REPORT_TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnalyticsReportRepository $reportRepository,
    ) {
    }

    /**
     * Отчёты, созданные этим пользователем (для списка «мои отчёты»).
     *
     * @return AnalyticsReport[]
     */
    public function findByUser(object $user): array
    {
        return $this->reportRepository->createQueryBuilder('r')
            ->andWhere('r.createdBy = :user')
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Получить отчёт по id с проверкой доступа: админ — любой отчёт, иначе только если текущий пользователь автор.
     */
    public function findByIdForUser(int $id, object $user): ?AnalyticsReport
    {
        $report = $this->reportRepository->find($id);
        if (!$report) {
            return null;
        }

        if (method_exists($user, 'getRoles') && in_array('ROLE_ADMIN', (array) $user->getRoles(), true)) {
            return $report;
        }

        $creator = $report->getCreatedBy();
        $userId = method_exists($user, 'getId') ? $user->getId() : null;
        if ($userId === null || $creator?->getId() === null || $creator->getId() !== $userId) {
            return null;
        }

        return $report;
    }

    public function findById(int $id): ?AnalyticsReport
    {
        return $this->reportRepository->find($id);
    }

    /**
     * Создать новый отчёт (draft) для организации и доски.
     * Находит текущую активную версию доски и определяет период по типу доски.
     */
    public function createReportForBoard(AbstractOrganization $organization, int $boardId, object $createdBy): AnalyticsReport
    {
        $boardRepo = $this->em->getRepository(AnalyticsBoard::class);
        $board = $boardRepo->find($boardId);
        if (!$board) {
            throw new \RuntimeException('Доска не найдена.');
        }

        $activeVersion = $board->getActiveVersion();
        if (!$activeVersion) {
            throw new \RuntimeException('У доски «' . $board->getName() . '» нет активной версии.');
        }

        $period = $this->getOrCreateCurrentPeriod($board);

        $existing = $this->reportRepository->findOneBy([
            'organization' => $organization,
            'board' => $board,
            'period' => $period,
        ]);
        if ($existing) {
            throw new \RuntimeException('Отчёт для этой доски и периода уже существует.');
        }

        $report = new AnalyticsReport();
        $report->setOrganization($organization);
        $report->setBoardVersion($activeVersion);
        $report->setPeriod($period);
        $report->setCreatedBy($createdBy);

        $this->em->persist($report);
        $this->em->flush();

        return $report;
    }

    private function getOrCreateCurrentPeriod(AnalyticsBoard $board): AnalyticsPeriod
    {
        $tz = new \DateTimeZone(self::REPORT_TIMEZONE);
        $now = new \DateTimeImmutable('now', $tz);

        $periodToCreate = match ($board->getPeriodType()) {
            AnalyticsPeriodType::Daily => AnalyticsPeriod::forDate($now),
            AnalyticsPeriodType::Weekly => AnalyticsPeriod::forIsoWeek((int) $now->format('o'), (int) $now->format('W')),
            AnalyticsPeriodType::Monthly => AnalyticsPeriod::forMonth((int) $now->format('Y'), (int) $now->format('n')),
        };

        $periodRepo = $this->em->getRepository(AnalyticsPeriod::class);
        $existing = $periodRepo->findOneBy([
            'type' => $periodToCreate->getType(),
            'startDate' => $periodToCreate->getStartDate(),
        ]);
        if ($existing instanceof AnalyticsPeriod) {
            return $existing;
        }

        $this->em->persist($periodToCreate);
        $this->em->flush();

        return $periodToCreate;
    }
}
