<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsReport;
use App\Entity\Analytics\AnalyticsReportValue;
use App\Entity\Organization\AbstractOrganization;
use App\Enum\Analytics\AnalyticsBoardVersionStatus;
use App\Enum\Analytics\AnalyticsReportStatus;
use App\Repository\Analytics\AnalyticsPeriodRepository;
use App\Repository\Analytics\AnalyticsReportRepository;
use Doctrine\ORM\EntityManagerInterface;

final class CreateReportService
{
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
        // Пользователь должен быть из той же организации
        $userOrg = $user->getOrganization();
        if ($userOrg && $report->getOrganization()->getId() === $userOrg->getId()) {
            return $report;
        }
        return null;
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

        // Проверяем, не существует ли уже отчёт для этой организации/доски/периода
        // Период определим по текущей ISO-неделе или ближайшему
        $periodRepo = $this->em->getRepository(\App\Entity\Analytics\AnalyticsPeriod::class);

        // Берём ближайший открытый период (с сортировкой по start_date ASC)
        $periods = $periodRepo->findBy(['isClosed' => false], ['startDate' => 'ASC']);
        if (empty($periods)) {
            throw new \RuntimeException('Нет открытых периодов для создания отчёта.');
        }
        $period = $periods[0];

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
}
