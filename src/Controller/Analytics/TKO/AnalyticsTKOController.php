<?php

declare(strict_types=1);

namespace App\Controller\Analytics\TKO;

use App\Entity\Analytics\TKO\AnalyticsTKO;
use App\Entity\Polygon\Polygon;
use App\Entity\User\User;
use App\Repository\Analytics\TKO\AnalyticsTKORepository;
use App\Repository\Polygon\PolygonRepository;
use App\Service\Analytics\TkoMetrics;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[IsGranted('ROLE_TKO')]
final class AnalyticsTKOController extends AbstractController
{
    #[Route('/analytics/tko', name: 'app_analytics_tko', methods: ['GET'])]
    public function index(
        Request $request,
        PolygonRepository $polygonRepository,
        AnalyticsTKORepository $analyticsRepository,
    ): Response {
        $polygons = $polygonRepository->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'name' => 'ASC']);
        $selectedPolygon = $this->resolvePolygon($request, $polygonRepository, $polygons);

        $monday = $this->resolveMonday($request->query->getString('week'));
        $sunday = $monday->modify('+6 days');

        [$days] = $this->buildDays($analyticsRepository, $selectedPolygon, $monday);

        return $this->render('analytics/tko/fill_report.html.twig', [
            'active_tab' => 'analytics_tko',
            'polygons' => $polygons,
            'selectedPolygon' => $selectedPolygon,
            'metrics' => TkoMetrics::METRICS,
            'days' => $days,
            'week' => $monday->format('Y-m-d'),
            'weekLabel' => sprintf('%s — %s', $monday->format('d.m'), $sunday->format('d.m')),
            'prevWeek' => $monday->modify('-7 days')->format('Y-m-d'),
            'nextWeek' => $monday->modify('+7 days')->format('Y-m-d'),
        ]);
    }

    #[Route('/analytics/tko/view', name: 'app_analytics_tko_view', methods: ['GET'])]
    public function view(
        Request $request,
        PolygonRepository $polygonRepository,
        AnalyticsTKORepository $analyticsRepository,
    ): Response {
        $polygons = $polygonRepository->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'name' => 'ASC']);
        $selectedPolygon = $this->resolvePolygon($request, $polygonRepository, $polygons);

        $monday = $this->resolveMonday($request->query->getString('week'));
        $sunday = $monday->modify('+6 days');

        [$days, $totals] = $this->buildDays($analyticsRepository, $selectedPolygon, $monday);

        return $this->render('analytics/tko/view_report.html.twig', [
            'active_tab' => 'analytics_tko_view',
            'polygons' => $polygons,
            'selectedPolygon' => $selectedPolygon,
            'metrics' => TkoMetrics::METRICS,
            'days' => $days,
            'totals' => $totals,
            'period' => $monday->format('Y-m-d'),
            'periodLabel' => sprintf('Период %s — %s', $monday->format('d.m'), $sunday->format('d.m')),
            'periodParam' => 'week',
            'prevPeriod' => $monday->modify('-7 days')->format('Y-m-d'),
            'nextPeriod' => $monday->modify('+7 days')->format('Y-m-d'),
        ]);
    }

    #[Route('/analytics/tko/view/week', name: 'app_analytics_tko_view_week', methods: ['GET'])]
    public function viewWeek(
        Request $request,
        PolygonRepository $polygonRepository,
        AnalyticsTKORepository $analyticsRepository,
    ): Response {
        $polygons = $polygonRepository->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'name' => 'ASC']);
        $selectedPolygon = $this->resolvePolygon($request, $polygonRepository, $polygons);

        // Месяц, недели которого показываем (обзор по неделям месяца)
        $month = $this->resolveMonthStart($request->query->getString('month'));

        [$columns, $totals] = $this->buildWeekColumns($analyticsRepository, $selectedPolygon, $month);

        return $this->render('analytics/tko/view_week_report.html.twig', [
            'active_tab' => 'analytics_tko_view_week',
            'polygons' => $polygons,
            'selectedPolygon' => $selectedPolygon,
            'metrics' => TkoMetrics::METRICS,
            'columns' => $columns,
            'totals' => $totals,
            'period' => $month->format('Y-m-d'),
            'periodLabel' => $this->monthLabel($month),
            'periodParam' => 'month',
            'prevPeriod' => $month->modify('-1 month')->format('Y-m-d'),
            'nextPeriod' => $month->modify('+1 month')->format('Y-m-d'),
        ]);
    }

    #[Route('/analytics/tko/view/month', name: 'app_analytics_tko_view_month', methods: ['GET'])]
    public function viewMonth(
        Request $request,
        PolygonRepository $polygonRepository,
        AnalyticsTKORepository $analyticsRepository,
    ): Response {
        $polygons = $polygonRepository->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'name' => 'ASC']);
        $selectedPolygon = $this->resolvePolygon($request, $polygonRepository, $polygons);

        // Год, месяцы которого показываем (обзор по месяцам года)
        $yearStart = $this->resolveYearStart($request->query->getString('year'));

        [$columns, $totals] = $this->buildMonthColumns($analyticsRepository, $selectedPolygon, $yearStart);

        return $this->render('analytics/tko/view_month_report.html.twig', [
            'active_tab' => 'analytics_tko_view_month',
            'polygons' => $polygons,
            'selectedPolygon' => $selectedPolygon,
            'metrics' => TkoMetrics::METRICS,
            'columns' => $columns,
            'totals' => $totals,
            'period' => $yearStart->format('Y-m-d'),
            'periodLabel' => $yearStart->format('Y') . ' год',
            'periodParam' => 'year',
            'prevPeriod' => $yearStart->modify('-1 year')->format('Y-m-d'),
            'nextPeriod' => $yearStart->modify('+1 year')->format('Y-m-d'),
        ]);
    }

    #[Route('/analytics/tko/view/summary', name: 'app_analytics_tko_view_summary', methods: ['GET'])]
    public function viewSummary(
        Request $request,
        PolygonRepository $polygonRepository,
        AnalyticsTKORepository $analyticsRepository,
    ): Response {
        $polygons = $polygonRepository->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'name' => 'ASC']);

        $monday = $this->resolveMonday($request->query->getString('week'));
        $sunday = $monday->modify('+6 days');

        [$rows, $totalsRow] = $this->buildSummaryRows($analyticsRepository, $polygons, $monday, $sunday);

        return $this->render('analytics/tko/view_summary_report.html.twig', [
            'active_tab' => 'analytics_tko_view_summary',
            'metrics' => TkoMetrics::METRICS,
            'rows' => $rows,
            'totalsRow' => $totalsRow,
            'week' => $monday->format('Y-m-d'),
            'weekLabel' => sprintf('Период %s — %s', $monday->format('d.m'), $sunday->format('d.m')),
            'prevWeek' => $monday->modify('-7 days')->format('Y-m-d'),
            'nextWeek' => $monday->modify('+7 days')->format('Y-m-d'),
        ]);
    }

    /**
     * Выгрузка текущей таблицы в xlsx. `level` повторяет страницу просмотра,
     * `period` — её же параметр периода (week/month/year под одним именем).
     */
    #[Route('/analytics/tko/export', name: 'app_analytics_tko_export', methods: ['GET'])]
    public function export(
        Request $request,
        PolygonRepository $polygonRepository,
        AnalyticsTKORepository $analyticsRepository,
    ): Response {
        $polygons = $polygonRepository->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'name' => 'ASC']);
        $level = $request->query->getString('level');
        $period = $request->query->getString('period');

        // В сводке строки — полигоны, а колонки — метрики; на остальных уровнях наоборот
        if ('summary' === $level) {
            $monday = $this->resolveMonday($period);
            $sunday = $monday->modify('+6 days');
            [$rows, $totalsRow] = $this->buildSummaryRows($analyticsRepository, $polygons, $monday, $sunday);

            $sheetRows = [array_merge(
                ['Итого по всем'],
                array_map($this->blankZeroTotal(...), $this->metricValues($totalsRow)),
            )];
            foreach ($rows as $row) {
                $sheetRows[] = array_merge([(string) $row['name']], $this->metricValues($row['values']));
            }

            return $this->streamXlsx(
                'Сводка ТКО',
                sprintf('Все полигоны — период %s — %s', $monday->format('d.m.Y'), $sunday->format('d.m.Y')),
                array_merge(['Полигон'], array_map($this->metricHeader(...), TkoMetrics::METRICS)),
                $sheetRows,
                sprintf('tko_svodka_%s.xlsx', $monday->format('Y-m-d')),
            );
        }

        $selectedPolygon = $this->resolvePolygon($request, $polygonRepository, $polygons);
        if (null === $selectedPolygon) {
            throw $this->createNotFoundException('Полигон не найден.');
        }

        if ('week' === $level) {
            $month = $this->resolveMonthStart($period);
            [$columns, $totals] = $this->buildWeekColumns($analyticsRepository, $selectedPolygon, $month);
            $labels = array_map(static fn (array $c): string => $c['label'] . ' ' . $c['sublabel'], $columns);
            $periodLabel = $this->monthLabel($month);
            $suffix = 'nedeli_' . $month->format('Y-m');
        } elseif ('month' === $level) {
            $yearStart = $this->resolveYearStart($period);
            [$columns, $totals] = $this->buildMonthColumns($analyticsRepository, $selectedPolygon, $yearStart);
            $labels = array_map(static fn (array $c): string => $c['label'] . ' ' . $c['sublabel'], $columns);
            $periodLabel = $yearStart->format('Y') . ' год';
            $suffix = 'mesyacy_' . $yearStart->format('Y');
        } else {
            $monday = $this->resolveMonday($period);
            [$columns, $totals] = $this->buildDays($analyticsRepository, $selectedPolygon, $monday);
            $labels = array_map(static fn (array $c): string => $c['dow'] . ' ' . $c['short'], $columns);
            $periodLabel = sprintf(
                'период %s — %s',
                $monday->format('d.m.Y'),
                $monday->modify('+6 days')->format('d.m.Y'),
            );
            $suffix = 'dni_' . $monday->format('Y-m-d');
        }

        $sheetRows = [];
        foreach (TkoMetrics::METRICS as $metric) {
            $row = [$this->metricHeader($metric)];
            foreach ($columns as $column) {
                $row[] = $column['values'][$metric['key']] ?? '';
            }
            $row[] = $this->blankZeroTotal($totals[$metric['key']] ?? '');
            $sheetRows[] = $row;
        }

        return $this->streamXlsx(
            'Аналитика ТКО',
            sprintf('Полигон «%s» — %s', (string) $selectedPolygon->getName(), $periodLabel),
            array_merge(['Метрика'], $labels, ['Итого']),
            $sheetRows,
            sprintf('tko_%s_%s.xlsx', $this->slug((string) $selectedPolygon->getName()), $suffix),
        );
    }

    #[Route('/analytics/tko/save', name: 'app_analytics_tko_save', methods: ['POST'])]
    public function save(
        Request $request,
        PolygonRepository $polygonRepository,
        AnalyticsTKORepository $analyticsRepository,
        EntityManagerInterface $em,
    ): Response {
        $polygonId = $request->request->getInt('polygon_id');
        $week = $request->request->getString('week');
        /** @var array<string, mixed> $daysPayload */
        $daysPayload = $request->request->all('days');

        if (!$this->isCsrfTokenValid('analytics_tko_save', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');

            return $this->redirectToTko($polygonId, $week);
        }

        $polygon = $polygonId > 0 ? $polygonRepository->find($polygonId) : null;
        if (null === $polygon) {
            throw $this->createNotFoundException('Полигон не найден.');
        }

        if ([] === $daysPayload) {
            $this->addFlash('warning', 'Нет данных для сохранения.');

            return $this->redirectToTko($polygonId, $week);
        }

        $user = $this->getUser();
        $saved = 0;

        foreach ($daysPayload as $dateStr => $rawValues) {
            if (!\is_array($rawValues) || !\is_string($dateStr)) {
                continue;
            }

            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateStr);
            if (false === $date) {
                $this->addFlash('error', sprintf('Некорректная дата: %s.', $dateStr));

                return $this->redirectToTko($polygonId, $week);
            }

            $record = $analyticsRepository->findOneByPolygonAndDate($polygon, $date);
            $isNew = null === $record;

            $hasValue = false;
            $pending = [];
            foreach (TkoMetrics::METRICS as $metric) {
                $raw = trim((string) ($rawValues[$metric['key']] ?? ''));
                $value = null;

                if ('' !== $raw) {
                    if ('num' === $metric['type']) {
                        $normalized = str_replace([' ', ','], ['', '.'], $raw);
                        if (!is_numeric($normalized)) {
                            $this->addFlash('error', sprintf(
                                '%s, «%s»: «%s» — не число.',
                                $date->format('d.m.Y'),
                                $metric['label'],
                                $raw,
                            ));

                            return $this->redirectToTko($polygonId, $week);
                        }
                        $value = $normalized;
                    } else {
                        $value = $raw;
                    }
                    $hasValue = true;
                }

                $metric['value'] = $value;
                $pending[] = $metric;
            }

            // Не создаём пустую строку
            if ($isNew && !$hasValue) {
                continue;
            }

            if ($isNew) {
                $record = new AnalyticsTKO();
                $record->setPolygon($polygon);
                $record->setReportDate($date);
                if ($user instanceof User) {
                    $record->setCreatedBy($user);
                }
            }

            foreach ($pending as $metric) {
                $record->{$this->setter($metric['key'])}($metric['value']);
            }

            $em->persist($record);
            ++$saved;
        }

        if (0 === $saved) {
            $this->addFlash('warning', 'Нет данных для сохранения.');

            return $this->redirectToTko($polygonId, $week);
        }

        $em->flush();
        $this->addFlash('success', sprintf('Сохранено: %s.', $polygon->getName()));

        return $this->redirectToTko($polygonId, $week);
    }

    private function redirectToTko(int $polygonId, string $week): Response
    {
        $params = [];
        if ($polygonId > 0) {
            $params['polygon_id'] = $polygonId;
        }
        if ('' !== $week) {
            $params['week'] = $week;
        }

        return $this->redirectToRoute('app_analytics_tko', $params);
    }

    private function resolveMonday(string $week): \DateTimeImmutable
    {
        try {
            $base = '' !== $week ? new \DateTimeImmutable($week) : new \DateTimeImmutable('today');
        } catch (\Exception) {
            $base = new \DateTimeImmutable('today');
        }

        return $base->modify('monday this week')->setTime(0, 0);
    }

    /**
     * Выбранный полигон: из запроса либо первый из списка.
     *
     * @param list<Polygon> $polygons
     */
    private function resolvePolygon(Request $request, PolygonRepository $polygonRepository, array $polygons): ?Polygon
    {
        $selectedPolygon = null;
        $polygonId = $request->query->getInt('polygon_id');
        if ($polygonId > 0) {
            $selectedPolygon = $polygonRepository->find($polygonId);
        }
        if (null === $selectedPolygon && [] !== $polygons) {
            $selectedPolygon = $polygons[0];
        }

        return $selectedPolygon;
    }

    private function resolveMonthStart(string $month): \DateTimeImmutable
    {
        try {
            $base = '' !== $month ? new \DateTimeImmutable($month) : new \DateTimeImmutable('today');
        } catch (\Exception) {
            $base = new \DateTimeImmutable('today');
        }

        return $base->modify('first day of this month')->setTime(0, 0);
    }

    private function resolveYearStart(string $year): \DateTimeImmutable
    {
        try {
            $base = '' !== $year ? new \DateTimeImmutable($year) : new \DateTimeImmutable('today');
        } catch (\Exception) {
            $base = new \DateTimeImmutable('today');
        }

        return $base->modify('first day of January this year')->setTime(0, 0);
    }

    private function monthLabel(\DateTimeImmutable $month): string
    {
        return TkoMetrics::MONTHS[(int) $month->format('n') - 1] . ' ' . $month->format('Y');
    }

    /**
     * Раскладывает агрегированные бакеты репозитория по колонкам периода и считает итог за весь диапазон.
     * Числовые метрики суммируются, текстовые (COUNT по дням) тоже суммируются как число дней с отметкой.
     *
     * @param list<array<string, mixed>>            $columns бакеты с ключом 'key' (Y-m-d начала бакета)
     * @param array<string, array<string, mixed>>   $buckets ключ — Y-m-d начала бакета
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function fillColumns(array $columns, array $buckets): array
    {
        $sums = array_fill_keys(array_column(TkoMetrics::METRICS, 'key'), 0.0);

        foreach ($columns as $index => $column) {
            $bucket = $buckets[$column['key']] ?? [];
            $values = [];

            foreach (TkoMetrics::METRICS as $metric) {
                $raw = $bucket[$metric['key']] ?? null;
                if (null !== $raw && '' !== $raw && is_numeric($raw)) {
                    $sums[$metric['key']] += (float) $raw;
                    $values[$metric['key']] = $this->normalizeNumber((string) (float) $raw);
                } else {
                    $values[$metric['key']] = '';
                }
            }

            $columns[$index]['values'] = $values;
        }

        $totals = [];
        foreach (TkoMetrics::METRICS as $metric) {
            $totals[$metric['key']] = $this->normalizeNumber((string) $sums[$metric['key']]);
        }

        return [$columns, $totals];
    }

    private function normalizeNumber(?string $value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value;
    }

    private function getter(string $key): string
    {
        return 'get' . str_replace('_', '', ucwords($key, '_'));
    }

    private function setter(string $key): string
    {
        return 'set' . str_replace('_', '', ucwords($key, '_'));
    }

    /**
     * Дни недели Пн–Вс по полигону с итогом за неделю.
     * Числовые метрики суммируются, текстовые — считается число дней с отметкой.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function buildDays(
        AnalyticsTKORepository $analyticsRepository,
        ?Polygon $polygon,
        \DateTimeImmutable $monday,
    ): array {
        // Загружаем записи недели и раскладываем по дате
        $byDate = [];
        if (null !== $polygon) {
            $records = $analyticsRepository->findByPolygonAndDateRange($polygon, $monday, $monday->modify('+6 days'));
            foreach ($records as $record) {
                $byDate[$record->getReportDate()->format('Y-m-d')] = $record;
            }
        }

        $days = [];
        $sums = array_fill_keys(array_column(TkoMetrics::METRICS, 'key'), 0.0);
        $counts = array_fill_keys(array_column(TkoMetrics::METRICS, 'key'), 0);
        for ($i = 0; $i < 7; ++$i) {
            $date = $monday->modify(sprintf('+%d days', $i));
            $key = $date->format('Y-m-d');
            $record = $byDate[$key] ?? null;

            $values = [];
            foreach (TkoMetrics::METRICS as $metric) {
                $raw = null !== $record ? $record->{$this->getter($metric['key'])}() : null;

                if ('num' === $metric['type']) {
                    $values[$metric['key']] = $this->normalizeNumber($raw);
                    if (null !== $raw && '' !== $raw && is_numeric($raw)) {
                        $sums[$metric['key']] += (float) $raw;
                    }
                } else {
                    $text = (string) ($raw ?? '');
                    $values[$metric['key']] = $text;
                    if ('' !== trim($text)) {
                        ++$counts[$metric['key']];
                    }
                }
            }

            $days[] = [
                'date' => $key,
                'dow' => TkoMetrics::DOW[$i],
                'short' => $date->format('d.m'),
                'values' => $values,
            ];
        }

        $totals = [];
        foreach (TkoMetrics::METRICS as $metric) {
            $totals[$metric['key']] = 'num' === $metric['type']
                ? $this->normalizeNumber((string) $sums[$metric['key']])
                : (string) $counts[$metric['key']];
        }

        return [$days, $totals];
    }

    /**
     * Календарные недели Пн–Вс, пересекающие месяц (крайние недели целиком).
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function buildWeekColumns(
        AnalyticsTKORepository $analyticsRepository,
        ?Polygon $polygon,
        \DateTimeImmutable $month,
    ): array {
        $monthEnd = $month->modify('last day of this month')->setTime(0, 0);
        $firstMonday = $month->modify('monday this week')->setTime(0, 0);
        $lastMonday = $monthEnd->modify('monday this week')->setTime(0, 0);

        $columns = [];
        for ($cursor = $firstMonday; $cursor <= $lastMonday; $cursor = $cursor->modify('+7 days')) {
            $weekEnd = $cursor->modify('+6 days');
            $columns[] = [
                'key' => $cursor->format('Y-m-d'),
                'label' => $cursor->format('d.m'),
                'sublabel' => '— ' . $weekEnd->format('d.m'),
                // Drill-down: клик по неделе открывает детальный просмотр этой недели
                'href' => $this->generateUrl('app_analytics_tko_view', [
                    'polygon_id' => $polygon?->getId(),
                    'week' => $cursor->format('Y-m-d'),
                ]),
            ];
        }

        $buckets = null !== $polygon
            ? $analyticsRepository->aggregateByPolygon($polygon->getId(), $firstMonday, $lastMonday->modify('+6 days'), 'week')
            : [];

        return $this->fillColumns($columns, $buckets);
    }

    /**
     * Двенадцать месяцев года.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function buildMonthColumns(
        AnalyticsTKORepository $analyticsRepository,
        ?Polygon $polygon,
        \DateTimeImmutable $yearStart,
    ): array {
        $yearEnd = $yearStart->modify('+1 year')->modify('-1 day');

        $columns = [];
        for ($i = 0; $i < 12; ++$i) {
            $monthStart = $yearStart->modify(sprintf('+%d months', $i));
            $columns[] = [
                'key' => $monthStart->format('Y-m-d'),
                'label' => TkoMetrics::MONTHS[$i],
                'sublabel' => $monthStart->format('Y'),
                // Drill-down: клик по месяцу открывает понедельный обзор этого месяца
                'href' => $this->generateUrl('app_analytics_tko_view_week', [
                    'polygon_id' => $polygon?->getId(),
                    'month' => $monthStart->format('Y-m-d'),
                ]),
            ];
        }

        $buckets = null !== $polygon
            ? $analyticsRepository->aggregateByPolygon($polygon->getId(), $yearStart, $yearEnd, 'month')
            : [];

        return $this->fillColumns($columns, $buckets);
    }

    /**
     * Строка на каждый активный полигон + итог по всем за неделю.
     *
     * @param list<Polygon> $polygons
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function buildSummaryRows(
        AnalyticsTKORepository $analyticsRepository,
        array $polygons,
        \DateTimeImmutable $monday,
        \DateTimeImmutable $sunday,
    ): array {
        $byPolygon = [];
        foreach ($analyticsRepository->aggregateWeeklyByPolygon($monday, $sunday) as $row) {
            $byPolygon[(int) $row['polygon_id']] = $row;
        }

        $rows = [];
        $totals = array_fill_keys(array_column(TkoMetrics::METRICS, 'key'), 0.0);
        foreach ($polygons as $polygon) {
            $agg = $byPolygon[$polygon->getId()] ?? [];
            $values = [];
            foreach (TkoMetrics::METRICS as $metric) {
                $raw = $agg[$metric['key']] ?? null;
                if (null !== $raw && '' !== $raw && is_numeric($raw)) {
                    $totals[$metric['key']] += (float) $raw;
                    $values[$metric['key']] = $this->normalizeNumber((string) (float) $raw);
                } else {
                    $values[$metric['key']] = '';
                }
            }

            $rows[] = [
                'name' => $polygon->getName(),
                'values' => $values,
            ];
        }

        $totalsRow = [];
        foreach (TkoMetrics::METRICS as $metric) {
            $totalsRow[$metric['key']] = $this->normalizeNumber((string) $totals[$metric['key']]);
        }

        return [$rows, $totalsRow];
    }

    /**
     * @param array<string, mixed> $metric
     */
    private function metricHeader(array $metric): string
    {
        return sprintf('%s, %s', $metric['name'], $metric['unit']);
    }

    /**
     * На страницах просмотра нулевой итог рисуется прочерком — в файле тоже оставляем пусто,
     * иначе метрики, которые никто не заполняет, дают колонку нулей.
     */
    private function blankZeroTotal(string $value): string
    {
        return '0' === $value ? '' : $value;
    }

    /**
     * Значения метрик в порядке TkoMetrics::METRICS.
     *
     * @param array<string, string> $values
     *
     * @return list<string>
     */
    private function metricValues(array $values): array
    {
        return array_map(
            static fn (array $metric): string => $values[$metric['key']] ?? '',
            TkoMetrics::METRICS,
        );
    }

    /**
     * Шапка занимает строки 1–3, данные идут с 4-й.
     *
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    private function streamXlsx(
        string $sheetTitle,
        string $caption,
        array $headers,
        array $rows,
        string $filename,
    ): StreamedResponse {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetTitle);

        // Период и полигон — внутри файла: имя файла до пользователя может не дойти
        $sheet->setCellValueExplicit([1, 1], $caption, DataType::TYPE_STRING);
        $sheet->getStyle([1, 1, 1, 1])->getFont()->setBold(true);

        foreach ($headers as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 3], $header, DataType::TYPE_STRING);
        }

        $lastColumn = count($headers);
        $sheet->getStyle([1, 3, $lastColumn, 3])->getFont()->setBold(true);
        $sheet->freezePane('B4');

        $rowNumber = 4;
        foreach ($rows as $row) {
            foreach ($row as $index => $value) {
                $this->writeCell($sheet, $index + 1, $rowNumber, $value);
            }
            ++$rowNumber;
        }

        foreach (range(1, $lastColumn) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        $response = new StreamedResponse(static function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        });

        $response->headers->set(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        );

        return $response;
    }

    /**
     * Числа пишем числами, иначе в Excel по колонке не посчитать сумму.
     * Пустое значение оставляем пустой ячейкой: на странице там «—», но здесь это сломало бы автосумму.
     */
    private function writeCell(Worksheet $sheet, int $column, int $row, string $value): void
    {
        if ('' === $value) {
            return;
        }

        if (is_numeric($value)) {
            $sheet->setCellValueExplicit([$column, $row], (float) $value, DataType::TYPE_NUMERIC);

            return;
        }

        $sheet->setCellValueExplicit([$column, $row], $value, DataType::TYPE_STRING);
    }

    /**
     * Имя файла обязано быть ASCII: HeaderUtils::makeDisposition бросает исключение на кириллице.
     */
    private function slug(string $value): string
    {
        $slug = (new AsciiSlugger())->slug($value)->lower()->toString();

        return '' !== $slug ? $slug : 'polygon';
    }
}
