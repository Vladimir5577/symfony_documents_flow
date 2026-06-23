<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsBoard;
use App\Entity\Analytics\AnalyticsPeriod;
use App\Entity\Analytics\AnalyticsReport;
use App\Entity\Organization\AbstractOrganization;
use App\Enum\Analytics\AnalyticsPeriodType;
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
     * Получить отчёт по id с проверкой доступа: админ/аналитик — любой отчёт, иначе только если текущий пользователь автор.
     */
    public function findByIdForUser(int $id, object $user): ?AnalyticsReport
    {
        $report = $this->reportRepository->find($id);
        if (!$report) {
            return null;
        }

        if ($this->hasFullReportAccess($user)) {
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
     * Текущий период доски (без создания в БД).
     */
    public function findCurrentPeriodForBoard(AnalyticsBoard $board): ?AnalyticsPeriod
    {
        $type = $board->getPeriodType();
        $currentStart = $this->currentPeriodStartDate($type, $this->nowInReportTimezone());

        return $this->em->getRepository(AnalyticsPeriod::class)->findOneBy([
            'type' => $type,
            'startDate' => $currentStart,
        ]);
    }

    /**
     * Подпись текущего периода для формы нового отчёта.
     */
    public function getCurrentPeriodDisplayLabel(AnalyticsBoard $board): string
    {
        $period = $this->findCurrentPeriodForBoard($board);
        if ($period !== null) {
            return $period->getDisplayLabel();
        }

        return $this->nowInReportTimezone()->format('d.m.Y');
    }

    /**
     * Список периодов доски: дозаполняет пропуски от самого раннего до текущего.
     *
     * @return AnalyticsPeriod[]
     */
    public function getAvailablePeriodsForBoard(AnalyticsBoard $board): array
    {
        $this->ensurePeriodsUpToCurrent($board);

        return $this->em->getRepository(AnalyticsPeriod::class)->findBy(
            ['type' => $board->getPeriodType()],
            ['startDate' => 'DESC'],
        );
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

    private function hasFullReportAccess(object $user): bool
    {
        if (!method_exists($user, 'getRoles')) {
            return false;
        }

        $roles = (array) $user->getRoles();

        return in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_ANALYTIC', $roles, true);
    }

    private function getOrCreateCurrentPeriod(AnalyticsBoard $board): AnalyticsPeriod
    {
        $this->ensurePeriodsUpToCurrent($board);

        $type = $board->getPeriodType();
        $currentStart = $this->currentPeriodStartDate($type, $this->nowInReportTimezone());

        return $this->ensurePeriodExists($type, $currentStart);
    }

    private function ensurePeriodsUpToCurrent(AnalyticsBoard $board): void
    {
        $type = $board->getPeriodType();
        $periodRepo = $this->em->getRepository(AnalyticsPeriod::class);
        $existing = $periodRepo->findBy(['type' => $type], ['startDate' => 'ASC']);

        $currentStart = $this->currentPeriodStartDate($type, $this->nowInReportTimezone());

        if ($existing === []) {
            $this->ensurePeriodExists($type, $currentStart);

            return;
        }

        $minStart = $existing[0]->getStartDate();
        $maxStart = $existing[array_key_last($existing)]->getStartDate();
        if ($currentStart > $maxStart) {
            $maxStart = $currentStart;
        }

        $this->ensurePeriodsInRange($type, $minStart, $maxStart);
    }

    private function ensurePeriodsInRange(
        AnalyticsPeriodType $type,
        \DateTimeImmutable $minStart,
        \DateTimeImmutable $maxStart,
    ): void {
        $periodRepo = $this->em->getRepository(AnalyticsPeriod::class);
        $cursor = $minStart;
        $dirty = false;

        while ($cursor <= $maxStart) {
            $candidate = $this->makePeriodForStartDate($type, $cursor);
            $existing = $periodRepo->findOneBy([
                'type' => $candidate->getType(),
                'startDate' => $candidate->getStartDate(),
            ]);
            if (!$existing instanceof AnalyticsPeriod) {
                $this->em->persist($candidate);
                $dirty = true;
            }

            $cursor = $this->nextPeriodStartDate($type, $candidate->getStartDate());
        }

        if ($dirty) {
            $this->em->flush();
        }
    }

    private function ensurePeriodExists(
        AnalyticsPeriodType $type,
        \DateTimeImmutable $startDate,
    ): AnalyticsPeriod {
        $candidate = $this->makePeriodForStartDate($type, $startDate);
        $existing = $this->em->getRepository(AnalyticsPeriod::class)->findOneBy([
            'type' => $candidate->getType(),
            'startDate' => $candidate->getStartDate(),
        ]);
        if ($existing instanceof AnalyticsPeriod) {
            return $existing;
        }

        $this->em->persist($candidate);
        $this->em->flush();

        return $candidate;
    }

    private function makePeriodForStartDate(
        AnalyticsPeriodType $type,
        \DateTimeImmutable $startDate,
    ): AnalyticsPeriod {
        return match ($type) {
            AnalyticsPeriodType::Daily => AnalyticsPeriod::forDate($startDate),
            AnalyticsPeriodType::Weekly => AnalyticsPeriod::forIsoWeek(
                (int) $startDate->format('o'),
                (int) $startDate->format('W'),
            ),
            AnalyticsPeriodType::Monthly => AnalyticsPeriod::forMonth(
                (int) $startDate->format('Y'),
                (int) $startDate->format('n'),
            ),
        };
    }

    private function nextPeriodStartDate(
        AnalyticsPeriodType $type,
        \DateTimeImmutable $startDate,
    ): \DateTimeImmutable {
        return match ($type) {
            AnalyticsPeriodType::Daily => $startDate->modify('+1 day'),
            AnalyticsPeriodType::Weekly => $startDate->modify('+7 days'),
            AnalyticsPeriodType::Monthly => $startDate->modify('first day of next month'),
        };
    }

    private function currentPeriodStartDate(AnalyticsPeriodType $type, \DateTimeImmutable $now): \DateTimeImmutable
    {
        return match ($type) {
            AnalyticsPeriodType::Daily => $now->setTime(0, 0, 0),
            AnalyticsPeriodType::Weekly => (new \DateTimeImmutable())->setISODate(
                (int) $now->format('o'),
                (int) $now->format('W'),
            ),
            AnalyticsPeriodType::Monthly => new \DateTimeImmutable(sprintf('%s-%s-01', $now->format('Y'), $now->format('m'))),
        };
    }

    private function nowInReportTimezone(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::REPORT_TIMEZONE));
    }
}
