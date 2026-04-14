<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsReport;
use App\Entity\Analytics\AnalyticsReportValue;
use App\Entity\Analytics\AnalyticsPeriod;
use App\Entity\Organization\AbstractOrganization;
use App\Enum\Analytics\AnalyticsBoardVersionStatus;
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
     * Получить все отчёты пользователя (по его организации).
     * @return AnalyticsReport[]
     */
    public function findByUser(object $user): array
    {
        // Получаем организацию пользователя
        $org = $user->getOrganization();
        if (!$org) {
            return [];
        }
        return $this->reportRepository->findBy(
            ['organization' => $org],
            ['createdAt' => 'DESC']
        );
    }

    /**
     * Получить отчёт по id, проверить доступ пользователя.
     */
    public function findByIdForUser(int $id, object $user): ?AnalyticsReport
    {
        $report = $this->reportRepository->find($id);
        if (!$report) {
            return null;
        }

        // Админ имеет доступ к любому отчёту.
        if (method_exists($user, 'getRoles') && in_array('ROLE_ADMIN', (array) $user->getRoles(), true)) {
            return $report;
        }

        // Пользователь должен быть из той же организации или дочерней/родительской по иерархии.
        $userOrg = $user->getOrganization();
        if ($userOrg && $report->getOrganization() && $this->isOrganizationInHierarchy($userOrg, $report->getOrganization())) {
            return $report;
        }
        return null;
    }

    private function isOrganizationInHierarchy(AbstractOrganization $userOrganization, AbstractOrganization $reportOrganization): bool
    {
        $current = $userOrganization;
        while ($current !== null) {
            if ($current->getId() !== null && $current->getId() === $reportOrganization->getId()) {
                return true;
            }
            $current = $current->getParent();
        }

        return false;
    }

    public function findById(int $id): ?AnalyticsReport
    {
        return $this->reportRepository->find($id);
    }

    /**
     * Создать новый отчёт (draft) для организации и периода.
     * Находит текущую published-версию доски.
     */
    public function createReportForBoard(AbstractOrganization $organization, int $boardId, object $createdBy): AnalyticsReport
    {
        $boardRepo = $this->em->getRepository(\App\Entity\Analytics\AnalyticsBoard::class);
        $board = $boardRepo->find($boardId);
        if (!$board) {
            throw new \RuntimeException('Доска не найдена.');
        }

        // Находим опубликованную версию
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

        // Период отчёта — текущая ISO-неделя (Europe/Moscow).
        // Если периода еще нет в БД, создаём его.
        $period = $this->getOrCreateCurrentPeriod();

        // Проверяем уникальность: один отчёт на организацию/доску/период
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
        $report->setBoardVersion($publishedVersion); // внутри setBoardVersion автоматически ставится board
        $report->setPeriod($period);
        $report->setCreatedBy($createdBy);
        // status = Draft по умолчанию

        $this->em->persist($report);
        $this->em->flush();

        return $report;
    }

    private function getOrCreateCurrentPeriod(): AnalyticsPeriod
    {
        $tz = new \DateTimeZone(self::REPORT_TIMEZONE);
        $now = new \DateTimeImmutable('now', $tz);
        $isoYear = (int) $now->format('o');
        $isoWeek = (int) $now->format('W');

        $periodRepo = $this->em->getRepository(AnalyticsPeriod::class);
        $period = $periodRepo->findOneBy([
            'isoYear' => $isoYear,
            'isoWeek' => $isoWeek,
        ]);
        if ($period instanceof AnalyticsPeriod) {
            return $period;
        }

        $periodToCreate = AnalyticsPeriod::forIsoWeek($isoYear, $isoWeek);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO analytics_periods (iso_year, iso_week, start_date, end_date, is_closed, description, created_at, updated_at)
             VALUES (:isoYear, :isoWeek, :startDate, :endDate, false, :description, NOW(), NOW())
             ON CONFLICT (iso_year, iso_week) DO NOTHING',
            [
                'isoYear' => $isoYear,
                'isoWeek' => $isoWeek,
                'startDate' => $periodToCreate->getStartDate()?->format('Y-m-d'),
                'endDate' => $periodToCreate->getEndDate()?->format('Y-m-d'),
                'description' => $periodToCreate->getDescription(),
            ]
        );

        $createdOrExistingPeriod = $periodRepo->findOneBy([
            'isoYear' => $isoYear,
            'isoWeek' => $isoWeek,
        ]);
        if ($createdOrExistingPeriod instanceof AnalyticsPeriod) {
            return $createdOrExistingPeriod;
        }

        throw new \RuntimeException(sprintf('Не удалось получить период %d-W%02d.', $isoYear, $isoWeek));
    }
}
