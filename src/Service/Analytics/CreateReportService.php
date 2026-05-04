<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsBoard;
use App\Entity\Analytics\AnalyticsPeriod;
use App\Entity\Analytics\AnalyticsReport;
use App\Entity\Analytics\AnalyticsReportValue;
use App\Entity\Organization\AbstractOrganization;
use App\Enum\Analytics\AnalyticsBoardVersionStatus;
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
     * Находит текущую published-версию доски и определяет период по типу доски.
     */
    public function createReportForBoard(AbstractOrganization $organization, int $boardId, object $createdBy): AnalyticsReport
    {
        $boardRepo = $this->em->getRepository(AnalyticsBoard::class);
        $board = $boardRepo->find($boardId);
        if (!$board) {
            throw new \RuntimeException('Доска не найдена.');
        }

        $publishedVersion = null;
        foreach ($board->getBoardVersions() as $version) {
            if ($version->getStatus() === AnalyticsBoardVersionStatus::Published) {
                $publishedVersion = $version;
                break;
            }
        }
        if (!$publishedVersion) {
            throw new \RuntimeException('Нет опубликованной версии доски «' . $board->getName() . '».');
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
        $report->setBoardVersion($publishedVersion);
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
        $periodRepo = $this->em->getRepository(AnalyticsPeriod::class);

        return match ($board->getPeriodType()) {
            AnalyticsPeriodType::Daily => $this->findOrCreateDailyPeriod($periodRepo, $now),
            AnalyticsPeriodType::Weekly => $this->findOrCreateWeeklyPeriod($periodRepo, $now),
            AnalyticsPeriodType::Monthly => $this->findOrCreateMonthlyPeriod($periodRepo, $now),
        };
    }

    private function findOrCreateDailyPeriod(object $periodRepo, \DateTimeImmutable $now): AnalyticsPeriod
    {
        $today = $now->setTime(0, 0, 0);
        $period = $periodRepo->findOneBy(['type' => AnalyticsPeriodType::Daily, 'periodDate' => $today]);
        if ($period instanceof AnalyticsPeriod) {
            return $period;
        }

        $periodToCreate = AnalyticsPeriod::forDate($today);
        $this->em->persist($periodToCreate);
        $this->em->flush();

        $createdOrExisting = $periodRepo->findOneBy(['type' => AnalyticsPeriodType::Daily, 'periodDate' => $today]);
        if ($createdOrExisting instanceof AnalyticsPeriod) {
            return $createdOrExisting;
        }

        throw new \RuntimeException(sprintf('Не удалось создать daily-период %s.', $today->format('d.m.Y')));
    }

    private function findOrCreateWeeklyPeriod(object $periodRepo, \DateTimeImmutable $now): AnalyticsPeriod
    {
        $isoYear = (int) $now->format('o');
        $isoWeek = (int) $now->format('W');

        $period = $periodRepo->findOneBy(['type' => AnalyticsPeriodType::Weekly, 'isoYear' => $isoYear, 'isoWeek' => $isoWeek]);
        if ($period instanceof AnalyticsPeriod) {
            return $period;
        }

        $periodToCreate = AnalyticsPeriod::forIsoWeek($isoYear, $isoWeek);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO analytics_periods (type, iso_year, iso_week, start_date, end_date, is_closed, description, created_at, updated_at)
             VALUES (:type, :isoYear, :isoWeek, :startDate, :endDate, false, :description, NOW(), NOW())
             ON CONFLICT DO NOTHING',
            [
                'type' => AnalyticsPeriodType::Weekly->value,
                'isoYear' => $isoYear,
                'isoWeek' => $isoWeek,
                'startDate' => $periodToCreate->getStartDate()?->format('Y-m-d'),
                'endDate' => $periodToCreate->getEndDate()?->format('Y-m-d'),
                'description' => $periodToCreate->getDescription(),
            ]
        );

        $createdOrExisting = $periodRepo->findOneBy(['type' => AnalyticsPeriodType::Weekly, 'isoYear' => $isoYear, 'isoWeek' => $isoWeek]);
        if ($createdOrExisting instanceof AnalyticsPeriod) {
            return $createdOrExisting;
        }

        throw new \RuntimeException(sprintf('Не удалось получить период %d-W%02d.', $isoYear, $isoWeek));
    }

    private function findOrCreateMonthlyPeriod(object $periodRepo, \DateTimeImmutable $now): AnalyticsPeriod
    {
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');

        $period = $periodRepo->findOneBy(['type' => AnalyticsPeriodType::Monthly, 'year' => $year, 'month' => $month]);
        if ($period instanceof AnalyticsPeriod) {
            return $period;
        }

        $periodToCreate = AnalyticsPeriod::forMonth($year, $month);
        $this->em->persist($periodToCreate);
        $this->em->flush();

        $createdOrExisting = $periodRepo->findOneBy(['type' => AnalyticsPeriodType::Monthly, 'year' => $year, 'month' => $month]);
        if ($createdOrExisting instanceof AnalyticsPeriod) {
            return $createdOrExisting;
        }

        throw new \RuntimeException(sprintf('Не удалось создать monthly-период %d-%02d.', $year, $month));
    }
}
