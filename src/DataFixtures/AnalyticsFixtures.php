<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Analytics\AnalyticsBoard;
use App\Entity\Analytics\AnalyticsBoardVersion;
use App\Entity\Analytics\AnalyticsBoardVersionMetric;
use App\Entity\Analytics\AnalyticsMetric;
use App\Entity\Analytics\AnalyticsOrganizationBoard;
use App\Entity\Analytics\AnalyticsPeriod;
use App\Entity\Analytics\AnalyticsReport;
use App\Entity\Analytics\AnalyticsReportValue;
use App\Entity\Organization\Organization;
use App\Entity\User\User;
use App\Enum\Analytics\AnalyticsMetricAggregationType;
use App\Enum\Analytics\AnalyticsBoardVersionStatus;
use App\Enum\Analytics\AnalyticsReportStatus;
use App\Service\Analytics\RecalculateAggregatesService;
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
    public function __construct(
        private readonly RecalculateAggregatesService $recalculateAggregatesService,
    ) {
    }

    public static function getGroups(): array
    {
        return ['analytics'];
    }
    /**
     * Определение метрик.
     * Каждая: [businessKey, name, type, unit, aggregationType, inputType]
     */
    private const METRICS = [
        ['cash_inflow',            'Поступление',             'currency', 'руб.', AnalyticsMetricAggregationType::Sum,  'number'],
        ['cash_outflow',           'Списание',                'currency', 'руб.', AnalyticsMetricAggregationType::Sum,  'number'],
        ['account_balance',        'Остаток на счетах',       'currency', 'руб.', AnalyticsMetricAggregationType::Last, 'number'],
        ['fuel_consumption',       'Расход топлива',          'number',   'л',    AnalyticsMetricAggregationType::Sum,  'number'],
        ['tko_export',             'Вывоз ТКО',               'number',   'т',    AnalyticsMetricAggregationType::Sum,  'number'],
        ['employees_hired',        'Прийнято сотрудников',    'count',    'чел.', AnalyticsMetricAggregationType::Sum,  'number'],
        ['employees_terminated',   'Уволено сотрудников',     'count',    'чел.', AnalyticsMetricAggregationType::Sum,  'number'],
        ['staff_planned_count',    'Штатная численность',     'count',    'чел.', AnalyticsMetricAggregationType::Last, 'number'],
        ['staff_actual_count',     'Фактическая численность', 'count',    'чел.', AnalyticsMetricAggregationType::Last, 'number'],
    ];

    /**
     * Определение досок и набора метрик для каждой.
     * Каждая: [name, description, metricBusinessKeys[]]
     */
    private const BOARDS = [
        [
            'key' => 'finance',
            'name' => 'Доска финансистов',
            'description' => 'Финансовые показатели организации за неделю',
            'metrics' => ['cash_inflow', 'cash_outflow', 'account_balance'],
        ],
        [
            'key' => 'mechanics',
            'name' => 'Доска механиков',
            'description' => 'Операционные показатели транспорта и вывоза ТКО',
            'metrics' => ['fuel_consumption', 'tko_export'],
        ],
        [
            'key' => 'hr',
            'name' => 'Доска отдела кадров',
            'description' => 'Кадровые показатели организации',
            'metrics' => ['employees_hired', 'employees_terminated', 'staff_planned_count', 'staff_actual_count'],
        ],
    ];

    private const YEAR = 2026;
    private const WEEKS_IN_YEAR = 52;

    /**
     * Количество недель для фикстур.
     * Генерируем с 1-й по (CURRENT_WEEK - 1), текущую неделю пользователь создаёт вручную.
     * ISO-неделя 16 апреля 2026 = 16, значит генерируем 1–15.
     */
    private const WEEKS_COUNT = 15;

    /**
     * Генератор значений для каждой метрики.
     * Возвращает числовое значение (string для DECIMAL) на основе org-индекса и week-индекса.
     * Для повторяемости — детерминированная функция.
     */
    private function valueFor(int $metricIdx, int $orgIdx, int $weekIdx): ?string
    {
        $baseValues = match ($metricIdx) {
            0 => 900000.0, // cash_inflow
            1 => 620000.0, // cash_outflow
            2 => 1500000.0,// account_balance
            3 => 150.0,    // fuel_consumption, л
            4 => 4800.0,   // tko_export, т
            5 => 9.0,      // employees_hired
            6 => 7.0,      // employees_terminated
            7 => 320.0,    // staff_planned_count
            8 => 295.0,    // staff_actual_count
            default => 100.0,
        };

        $orgScale = [0.74, 0.89, 1.03, 1.16, 1.31, 0.92, 1.27][$orgIdx] ?? (1.0 + $orgIdx * 0.09);
        $phase = ($metricIdx * 0.41) + ($orgIdx * 0.67);
        $x = (float) $weekIdx;

        // Сильно различающиеся паттерны по филиалам.
        $branchPattern = match ($orgIdx % 7) {
            0 => 1.0 + 0.32 * sin($x * 0.95 + $phase) - 0.18 * cos($x * 0.31),
            1 => 0.92 + 0.42 * sin($x * 0.58 + $phase) + 0.07 * $x / self::WEEKS_IN_YEAR,
            2 => 0.84 + ((($weekIdx + $metricIdx) % 6) / 6.0) * 0.62 - 0.18,
            3 => 1.08 - 0.20 * sin($x * 0.22) + 0.33 * cos($x * 1.18 + $phase),
            4 => 0.86 + 0.024 * $x + 0.48 * sin($x * 0.74 + $phase),
            5 => 0.90 + 0.14 * floor(($x + 2.0) / 3.0) / 4.0 + 0.20 * sin($x * 1.5 + $phase),
            6 => 0.98 + 0.29 * sin($x * 0.41 + $phase) - 0.24 * sin($x * 1.33 + $phase * 0.5),
            default => 1.0,
        };

        // Сезонность и резкие шоки, чтобы волатильность была заметной.
        $seasonality = 1.0 + 0.22 * sin(($x / 52.0) * 2.0 * M_PI + $metricIdx * 0.35);
        $shock = 1.0;
        if ((($weekIdx + $orgIdx + $metricIdx) % 9) === 0) {
            $shock += 0.55;
        } elseif ((($weekIdx + 2 * $orgIdx + $metricIdx) % 11) === 0) {
            $shock -= 0.38;
        }

        // Более крупный детерминированный шум.
        $seed = ($metricIdx + 1) * 211 + ($orgIdx + 2) * 83 + ($weekIdx + 3) * 37;
        $noise = (($seed % 31) - 15) / 100.0;

        $value = $baseValues * $orgScale * $branchPattern * $seasonality * $shock * (1.0 + $noise);
        $value = max($baseValues * 0.20, $value);

        return number_format(round($value, 2), 2, '.', '');
    }

    public function load(ObjectManager $manager): void
    {
        echo "\n=== AnalyticsFixtures ===\n";

        // --- Организации верхнего уровня (parent IS NULL), первые 7 ---
        $orgRepo = $manager->getRepository(Organization::class);
        $organizations = $orgRepo->findBy(['parent' => null], orderBy: ['id' => 'ASC'], limit: 7);
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
        $metricByBusinessKey = [];
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
            $metricByBusinessKey[$businessKey] = $metric;
        }
        $manager->flush();
        echo "  Метрики: " . count($metrics) . "\n";

        // === 2. Доски + published версии ===
        $boardVersions = [];
        $allBoardKeys = [];
        foreach (self::BOARDS as $boardConfig) {
            $board = new AnalyticsBoard();
            $board->setName($boardConfig['name']);
            $board->setDescription($boardConfig['description']);
            $manager->persist($board);
            $manager->flush();

            $version = new AnalyticsBoardVersion();
            $version->setBoard($board);
            $version->setVersionNumber(1);
            $version->setStatus(AnalyticsBoardVersionStatus::Published);
            $manager->persist($version);
            $manager->flush();

            $position = 1;
            foreach ($boardConfig['metrics'] as $metricBusinessKey) {
                $metric = $metricByBusinessKey[$metricBusinessKey] ?? null;
                if ($metric === null) {
                    continue;
                }

                $bvm = new AnalyticsBoardVersionMetric();
                $bvm->setBoardVersion($version);
                $bvm->setMetric($metric);
                $bvm->setPosition($position++);
                // Для отдельных профильных досок делаем метрики обязательными.
                $bvm->setIsRequired(true);
                $manager->persist($bvm);
            }

            $manager->flush();
            // Обновляем коллекцию versionMetrics в памяти
            $manager->refresh($version);
            $boardVersions[$boardConfig['key']] = $version;
            $allBoardKeys[] = $boardConfig['key'];
            echo "  Доска: \"{$board->getName()}\", версия v1 (published), метрик: " . count($boardConfig['metrics']) . "\n";
        }

        // === 3. Периоды (с начала года до текущей недели, не включая текущую) ===
        $periods = [];
        for ($week = 1; $week <= self::WEEKS_COUNT; $week++) {
            $period = AnalyticsPeriod::forIsoWeek(self::YEAR, $week);
            $period->setIsClosed(true);
            $manager->persist($period);
            $periods[] = $period;
        }
        $manager->flush();
        echo "  Периоды: " . count($periods) . "\n";

        // === 4. Связи org -> board ---
        foreach ($organizations as $orgIdx => $org) {
            foreach ($allBoardKeys as $boardKey) {
                $boardVersion = $boardVersions[$boardKey] ?? null;
                if ($boardVersion === null) {
                    continue;
                }
                $orgBoard = new AnalyticsOrganizationBoard();
                $orgBoard->setOrganization($org);
                $orgBoard->setBoard($boardVersion->getBoard());
                // Первые 3 организации — обязательно, остальные — опционально.
                $orgBoard->setIsRequired($orgIdx < 3);
                $manager->persist($orgBoard);
            }
        }
        $manager->flush();
        echo "  Связи организация↔доска: " . ($orgCount * count($boardVersions)) . "\n";

        // === 5. Отчёты и значения ===
        $totalValues = 0;
        $totalReports = 0;

        foreach ($organizations as $orgIdx => $org) {
            $allWeekIndices = range(0, count($periods) - 1);

            foreach ($allWeekIndices as $weekIdx) {
                if ($weekIdx >= count($periods)) {
                    continue;
                }
                $period = $periods[$weekIdx];

                // Все периоды закрыты — все отчёты утверждены
                $status = AnalyticsReportStatus::Approved;

                foreach ($allBoardKeys as $boardKey) {
                    $boardVersion = $boardVersions[$boardKey] ?? null;
                    if ($boardVersion === null) {
                        continue;
                    }
                    $report = new AnalyticsReport();
                    $report->setOrganization($org);
                    $report->setBoardVersion($boardVersion);
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
                    $boardVersionMetrics = $boardVersion->getVersionMetrics();
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
        }
        $manager->flush();
        echo "  Отчёты: {$totalReports} (значений: {$totalValues})\n";

        $recalculatedReports = $this->recalculateAggregatesService->recalculateAll();
        echo "  Агрегаты пересчитаны по отчётам: {$recalculatedReports}\n";

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
