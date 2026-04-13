<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Analytics\AnalyticsAggregatedData;
use App\Entity\Analytics\AnalyticsBoard;
use App\Entity\Analytics\AnalyticsBoardVersion;
use App\Entity\Analytics\AnalyticsBoardVersionMetric;
use App\Entity\Analytics\AnalyticsMetric;
use App\Entity\Analytics\AnalyticsOrganizationBoard;
use App\Entity\Analytics\AnalyticsPeriod;
use App\Entity\Analytics\AnalyticsReport;
use App\Entity\Analytics\AnalyticsReportValue;
use App\Entity\Organization\Organization;
use App\Entity\User\Role;
use App\Entity\User\User;
use App\Enum\Analytics\AnalyticsMetricAggregationType;
use App\Enum\Analytics\AnalyticsBoardVersionStatus;
use App\Enum\Analytics\AnalyticsReportStatus;
use App\Enum\UserRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Фикстуры аналитики.
 * - Берёт организации и admin-пользователя из БД
 * - Создаёт метрики, доски, периоды, отчёты, значения, агрегаты
 */
class AnalyticsFixtures extends Fixture implements FixtureGroupInterface
{
    public static function getGroups(): array
    {
        return ['analytics'];
    }
    /**
     * Определение метрик.
     * Каждая: [businessKey, name, type, unit, aggregationType, inputType]
     */
    private const METRICS = [
        ['fuel_consumption',   'Расход топлива',     'number',   'л',       AnalyticsMetricAggregationType::Sum,      'number'],
        ['spare_parts_cost',   'Расход запчастей',   'currency', 'руб.',    AnalyticsMetricAggregationType::Sum,      'number'],
        ['profit',             'Прибыль',            'currency', 'руб.',    AnalyticsMetricAggregationType::Sum,      'number'],
        ['expense',            'Расход общий',       'currency', 'руб.',    AnalyticsMetricAggregationType::Sum,      'number'],
        ['revenue',            'Выручка',            'currency', 'руб.',    AnalyticsMetricAggregationType::Sum,      'number'],
        ['downtime_hours',     'Простой техники',    'number',  'ч',        AnalyticsMetricAggregationType::Sum,      'number'],
        ['employee_count',     'Кол-во сотрудников', 'count',    'чел.',    AnalyticsMetricAggregationType::Last,     'number'],
        ['work_output',        'Выработка',          'number',   'м³',      AnalyticsMetricAggregationType::Sum,      'number'],
        ['transport_cost',     'Расход на транспорт','currency', 'руб.',    AnalyticsMetricAggregationType::Sum,      'number'],
        ['material_cost',      'Расход материалов',  'currency', 'руб.',    AnalyticsMetricAggregationType::Sum,      'number'],
        ['overhead_cost',      'Накладные расходы',  'currency', 'руб.',    AnalyticsMetricAggregationType::Sum,      'number'],
        ['salary_fund',        'Фонд оплаты труда',  'currency', 'руб.',    AnalyticsMetricAggregationType::Sum,      'number'],
    ];

    private const BOARD_NAME = 'Операционная аналитика';

    /** ISO-недели для данных */
    private const WEEKS = [
        [2025, 1],
        [2025, 2],
        [2025, 3],
        [2025, 4],
        [2025, 5],
        [2025, 6],
        [2025, 7],
        [2025, 8],
        [2025, 9],
        [2025, 10],
    ];

    /** Отчёты, которые будут approved (индекс организации => список недель-индексов) */
    private const APPROVED = [
        0 => [0, 1, 2, 3],
        1 => [0, 1, 2, 3, 4],
        2 => [0, 1, 2, 3, 4, 5],
        3 => [0, 1, 2, 3, 4, 5, 6],
        4 => [0, 1, 2, 3, 4, 5, 6, 7],
        5 => [0, 1, 2],
        6 => [0, 1],
    ];

    /** Отчёты, которые будут submitted */
    private const SUBMITTED = [
        0 => [4],
        2 => [6],
        4 => [8],
        5 => [3],
        6 => [2, 3],
    ];

    /** Остальное — draft */
    private const DRAFT = [
        1 => [5, 6, 7, 8, 9],
        3 => [7, 8, 9],
        5 => [4, 5, 6, 7, 8, 9],
        6 => [4, 5, 6, 7, 8, 9],
    ];

    /**
     * Генератор значений для каждой метрики.
     * Возвращает числовое значение (string для DECIMAL) на основе org-индекса и week-индекса.
     * Для повторяемости — детерминированная функция.
     */
    private function valueFor(int $metricIdx, int $orgIdx, int $weekIdx): ?string
    {
        $baseValues = match ($metricIdx) {
            0  => 150.0,   // fuel_consumption, л
            1  => 45000.0, // spare_parts_cost
            2  => 500000.0,// profit
            3  => 300000.0,// expense
            4  => 800000.0,// revenue
            5  => 24.0,    // downtime_hours
            6  => 45.0,    // employee_count
            7  => 200.0,   // work_output (m³)
            8  => 80000.0, // transport_cost
            9  => 120000.0,// material_cost
            10 => 50000.0, // overhead_cost
            11 => 250000.0,// salary_fund
            default => 100.0,
        };

        $orgScale = [0.78, 0.92, 1.05, 1.18, 1.32, 0.88, 1.24][$orgIdx] ?? (1.0 + $orgIdx * 0.07);
        $phase = ($metricIdx * 0.33) + ($orgIdx * 0.57);
        $x = (float) $weekIdx;

        // Для каждой организации — свой характер линии:
        // 0: почти прямая нисходящая; 1: синус с ростом; 2: пила; 3: U-образная;
        // 4: резкая волна; 5: ступени; 6: волна + локальные пики.
        $shapeFactor = match ($orgIdx) {
            0 => 1.18 - 0.038 * $x,
            1 => 0.90 + 0.030 * $x + 0.16 * sin($x * 0.80 + $phase),
            2 => 0.86 + 0.020 * $x + (((($weekIdx + $metricIdx) % 4) / 4.0) * 0.18 - 0.04),
            3 => 0.82 + 0.010 * (($x - 5.0) ** 2),
            4 => 0.95 + 0.028 * $x + 0.24 * sin($x * 1.05 + $phase),
            5 => 0.88 + 0.045 * floor(($x + 1.0) / 2.0) / 5.0,
            6 => 0.92 + 0.024 * $x + 0.14 * sin($x * 0.65 + $phase) + (($weekIdx % 5 === 0) ? 0.11 : 0.0),
            default => 1.0 + 0.018 * $x,
        };

        // Метрика тоже добавляет свой ритм, чтобы линии разных метрик не были одинаковыми.
        $metricRhythm = 1.0 + 0.04 * sin($x * (0.45 + ($metricIdx % 4) * 0.12) + $metricIdx * 0.2);

        // Детерминированный небольшой шум.
        $seed = ($metricIdx + 1) * 193 + ($orgIdx + 2) * 71 + ($weekIdx + 3) * 29;
        $noise = (($seed % 17) - 8) / 100.0;

        $value = $baseValues * $orgScale * $shapeFactor * $metricRhythm * (1.0 + $noise);
        $value = max($baseValues * 0.20, $value);

        return number_format(round($value, 2), 2, '.', '');
    }

    public function load(ObjectManager $manager): void
    {
        echo "\n=== AnalyticsFixtures ===\n";

        // --- Организации (из БД, первые 7) ---
        $orgRepo = $manager->getRepository(Organization::class);
        $organizations = $orgRepo->findBy([], orderBy: ['id' => 'ASC'], limit: 7);
        $orgCount = count($organizations);

        if ($orgCount < 7) {
            echo "  WARNING: В базе только {$orgCount} организаций (нужно 7). Загружаем что есть.\n";
            if ($orgCount === 0) {
                echo "  Нет организаций — пропускаем аналитику.\n";
                return;
            }
        }
        echo "  Организации: {$orgCount}\n";

        // --- Пользователи на организацию (директор/менеджер) ---
        $userRepo = $manager->getRepository(User::class);
        $orgUsers = [];
        foreach ($organizations as $orgIdx => $org) {
            /** @var ?User $orgUser */
            $orgUser = $userRepo->createQueryBuilder('u')
                ->where('u.organization = :org')
                ->setParameter('org', $org)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($orgUser === null) {
                // Берём любого первого пользователя как fallback
                $orgUser = $userRepo->findOneBy([]);
            }
            $orgUsers[] = $orgUser;
        }

        if ($orgUsers[0] === null) {
            echo "  Нет пользователей — пропускаем.\n";
            return;
        }

        // === 1. Метрики ===
        $metrics = [];
        foreach (self::METRICS as [$businessKey, $name, $type, $unit, $aggregationType, $inputType]) {
            $metric = new AnalyticsMetric();
            $metric->setBusinessKey($businessKey);
            $metric->setName($name);
            $metric->setType($type);
            $metric->setUnit($unit);
            $metric->setAggregationType($aggregationType);
            $metric->setInputType($inputType);
            $manager->persist($metric);
            $metrics[] = $metric;
        }
        $manager->flush();
        echo "  Метрики: " . count($metrics) . "\n";

        // === 2. Доска + published версия ===
        $board = new AnalyticsBoard();
        $board->setName(self::BOARD_NAME);
        $board->setDescription('Основные операционные показатели организации за неделю');
        $manager->persist($board);
        $manager->flush();

        // Создаём published версию
        $version = new AnalyticsBoardVersion();
        $version->setBoard($board);
        $version->setVersionNumber(1);
        $version->setStatus(AnalyticsBoardVersionStatus::Published);
        $manager->persist($version);
        $manager->flush();

        // Добавляем метрики в версию доски
        foreach ($metrics as $idx => $metric) {
            $bvm = new AnalyticsBoardVersionMetric();
            $bvm->setBoardVersion($version);
            $bvm->setMetric($metric);
            $bvm->setPosition($idx + 1);
            // Первые 6 метрик — required
            $bvm->setIsRequired($idx < 6);
            $manager->persist($bvm);
        }
        $manager->flush();
        echo "  Доска: \"{$board->getName()}\", версия v1 (published), метрик: " . count($metrics) . "\n";

        // === 3. Периоды ===
        $periods = [];
        foreach (self::WEEKS as [$year, $week]) {
            $period = AnalyticsPeriod::forIsoWeek($year, $week);
            // Закрываем ранние периоды (индексы 0-6)
            $period->setIsClosed($period->getIsoWeek() <= 7);
            $manager->persist($period);
            $periods[] = $period;
        }
        $manager->flush();
        echo "  Периоды: " . count($periods) . "\n";

        // === 4. Связи org -> board ---
        foreach ($organizations as $orgIdx => $org) {
            $orgBoard = new AnalyticsOrganizationBoard();
            $orgBoard->setOrganization($org);
            $orgBoard->setBoard($board);
            // Первые 3 — required, остальные — нет
            $orgBoard->setIsRequired($orgIdx < 3);
            $manager->persist($orgBoard);
        }
        $manager->flush();
        echo "  Связи организация↔доска: {$orgCount}\n";

        // === 5. Отчёты и значения ===
        $totalValues = 0;
        $totalReports = 0;

        foreach ($organizations as $orgIdx => $org) {
            $approvedWeekIndices = self::APPROVED[$orgIdx] ?? [];
            $submittedWeekIndices = self::SUBMITTED[$orgIdx] ?? [];
            $draftWeekIndices = self::DRAFT[$orgIdx] ?? [];

            /** @var int[] $allWeekIndices */
            $allWeekIndices = [...$approvedWeekIndices, ...$submittedWeekIndices, ...$draftWeekIndices];

            foreach ($allWeekIndices as $weekIdx) {
                if ($weekIdx >= count($periods)) {
                    continue;
                }
                $period = $periods[$weekIdx];

                if (in_array($weekIdx, $approvedWeekIndices, true)) {
                    $status = AnalyticsReportStatus::Approved;
                } elseif (in_array($weekIdx, $submittedWeekIndices, true)) {
                    $status = AnalyticsReportStatus::Submitted;
                } else {
                    $status = AnalyticsReportStatus::Draft;
                }

                $report = new AnalyticsReport();
                $report->setOrganization($org);
                $report->setBoardVersion($version);
                $report->setPeriod($period);
                $report->setCreatedBy($orgUsers[$orgIdx] ?? $orgUsers[0]);
                $report->setStatus($status);

                if ($status === AnalyticsReportStatus::Submitted || $status === AnalyticsReportStatus::Approved) {
                    $report->setIsComplete(true);
                }

                $weekStartDate = $period->getStartDate();
                $createdAt = clone $weekStartDate;
                $report->setCreatedAt($createdAt->modify('monday this week 09:00:00'));

                if ($status === AnalyticsReportStatus::Approved) {
                    $submittedAt = clone $weekStartDate;
                    $report->setSubmittedAt($submittedAt->modify('saturday this week 14:00:00'));
                    $approvedAt = clone $weekStartDate;
                    $report->setApprovedAt($approvedAt->modify('sunday this week 18:00:00'));
                    $report->setApprovedBy($orgUsers[$orgIdx] ?? $orgUsers[0]);
                } elseif ($status === AnalyticsReportStatus::Submitted) {
                    $submittedAt = clone $weekStartDate;
                    $report->setSubmittedAt($submittedAt->modify('saturday this week 14:00:00'));
                }

                $effectiveAt = $report->getSubmittedAt() ?: $report->getCreatedAt();
                $boardVersionMetrics = $version->getVersionMetrics();
                $metricsToFill = $status === AnalyticsReportStatus::Draft
                    ? $this->randomMetricsForDraft($boardVersionMetrics, $orgIdx, $weekIdx)
                    : $boardVersionMetrics;

                foreach ($metricsToFill as $bvm) {
                    $metric = $bvm->getMetric();
                    $mIdx = $this->findMetricIndex($metrics, $metric);
                    if ($mIdx === null) {
                        continue;
                    }

                    $value = $this->valueFor($mIdx, $orgIdx, $weekIdx);
                    if ($value === null) {
                        continue;
                    }

                    $reportValue = new AnalyticsReportValue();
                    $reportValue->setBoardVersionMetric($bvm);
                    $reportValue->setMetricNameSnapshot($metric->getName());
                    $reportValue->setMetricUnitSnapshot($metric->getUnit());
                    $reportValue->setMetricTypeSnapshot($metric->getType());
                    $reportValue->setValueNumber($value);
                    $reportValue->setCreatedBy($orgUsers[$orgIdx] ?? $orgUsers[0]);
                    $reportValue->setEffectiveAt(clone $effectiveAt);

                    $report->addValue($reportValue);
                    $totalValues++;

                    $manager->persist($reportValue);
                }

                $manager->persist($report);
                $totalReports++;
            }
        }
        $manager->flush();
        echo "  Отчёты: {$totalReports} (значений: {$totalValues})\n";

        // === 6. Агрегированные данные (хардкод, для тестирования фронта) ===
        $aggCount = 0;
        $firstMetric = $metrics[0];  // fuel_consumption
        $secondMetric = $metrics[1]; // spare_parts_cost
        $thirdMetric = $metrics[2];  // profit

        foreach ($organizations as $orgIdx => $org) {
            $approvedWeekIndices = self::APPROVED[$orgIdx] ?? [];
            foreach ($approvedWeekIndices as $weekIdx) {
                if ($weekIdx >= count($periods)) {
                    continue;
                }
                $period = $periods[$weekIdx];

                foreach ([$firstMetric, $secondMetric, $thirdMetric] as $aggMetricIdx => $aggMetric) {
                    $agg = new AnalyticsAggregatedData();
                    $agg->setMetric($aggMetric);
                    $agg->setBoard($board);
                    $agg->setPeriod($period);
                    $agg->setOrganization($org);
                    $agg->setMetricNameSnapshot($aggMetric->getName());
                    $agg->setMetricUnitSnapshot($aggMetric->getUnit());
                    $agg->setMetricTypeSnapshot($aggMetric->getType());
                    $agg->setAggregationType($aggMetric->getAggregationType()->value);

                    $rawValue = $this->valueFor($aggMetricIdx, $orgIdx, $weekIdx);
                    $agg->setValue($rawValue);
                    $agg->setSourceCount(1);
                    $agg->setEffectiveAt($period->getStartDate());
                    $agg->setCalculatedAt(new \DateTimeImmutable());

                    // report — первый approved отчёт этой org/периода
                    // Находим его: org × period, approved
                    /** @var AnalyticsReport|null $foundReport */
                    $foundReport = $manager->getRepository(AnalyticsReport::class)->findOneBy([
                        'organization' => $org,
                        'period' => $period,
                        'status' => AnalyticsReportStatus::Approved,
                    ]);
                    if ($foundReport) {
                        $agg->setReport($foundReport);
                    }

                    $manager->persist($agg);
                    $aggCount++;
                }
            }
        }
        $manager->flush();
        echo "  Агрегаты: {$aggCount} записей (3 метрики × approved-недели)\n";
        echo "=== AnalyticsFixtures готово ===\n\n";
    }

    /**
     * Для draft-отчётов заполняем не все метрики (частичное заполнение).
     * Чем больше orgIdx, тем меньше заполнено.
     */
    private function randomMetricsForDraft(iterable $boardVersionMetrics, int $orgIdx, int $weekIdx): array
    {
        $all = iterator_to_array($boardVersionMetrics);
        $total = count($all);

        // Заполняем от 30% до 70% в зависимости от org+week
        $fillPct = 0.3 + (($orgIdx + $weekIdx) % 5) * 0.1;
        $count = max(1, (int) ceil($total * $fillPct));

        // Берём первые $count метрик (детерминированно)
        $skip = ($orgIdx + $weekIdx) % max(1, $total - $count);
        return array_slice($all, $skip, $count);
    }

    /**
     * Найти индекс метрики в массиве по ссылке на AnalyticsMetric.
     */
    private function findMetricIndex(array $metrics, ?AnalyticsMetric $metric): ?int
    {
        if ($metric === null) {
            return null;
        }
        // Сравниваем по businessKey, т.к. это одна и та же сущность
        $targetKey = $metric->getBusinessKey();
        foreach ($metrics as $idx => $m) {
            if ($m->getBusinessKey() === $targetKey) {
                return $idx;
            }
        }
        // Fallback — это должна быть та же entity
        $id = $metric->getId();
        foreach ($metrics as $idx => $m) {
            if ($m->getId() === $id) {
                return $idx;
            }
        }
        return null;
    }

}
