<?php

declare(strict_types=1);

namespace App\Controller\Analytics\TKO;

use App\Entity\Analytics\TKO\AnalyticsTKO;
use App\Entity\User\User;
use App\Repository\Analytics\TKO\AnalyticsTKORepository;
use App\Repository\Polygon\PolygonRepository;
use App\Service\Analytics\TkoMetrics;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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

        // Выбранный полигон: из запроса либо первый из списка
        $selectedPolygon = null;
        $polygonId = $request->query->getInt('polygon_id');
        if ($polygonId > 0) {
            $selectedPolygon = $polygonRepository->find($polygonId);
        }
        if (null === $selectedPolygon && [] !== $polygons) {
            $selectedPolygon = $polygons[0];
        }

        $monday = $this->resolveMonday($request->query->getString('week'));
        $sunday = $monday->modify('+6 days');

        // Загружаем записи недели и раскладываем по дате
        $byDate = [];
        if (null !== $selectedPolygon) {
            foreach ($analyticsRepository->findByPolygonAndDateRange($selectedPolygon, $monday, $sunday) as $record) {
                $byDate[$record->getReportDate()->format('Y-m-d')] = $record;
            }
        }

        $days = [];
        for ($i = 0; $i < 7; ++$i) {
            $date = $monday->modify(sprintf('+%d days', $i));
            $key = $date->format('Y-m-d');
            $record = $byDate[$key] ?? null;

            $values = [];
            foreach (TkoMetrics::METRICS as $metric) {
                $raw = null !== $record ? $record->{$this->getter($metric['key'])}() : null;
                $values[$metric['key']] = 'num' === $metric['type']
                    ? $this->normalizeNumber($raw)
                    : (string) ($raw ?? '');
            }

            $days[] = [
                'date' => $key,
                'dow' => TkoMetrics::DOW[$i],
                'short' => $date->format('d.m'),
                'values' => $values,
            ];
        }

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

        // Выбранный полигон: из запроса либо первый из списка
        $selectedPolygon = null;
        $polygonId = $request->query->getInt('polygon_id');
        if ($polygonId > 0) {
            $selectedPolygon = $polygonRepository->find($polygonId);
        }
        if (null === $selectedPolygon && [] !== $polygons) {
            $selectedPolygon = $polygons[0];
        }

        $monday = $this->resolveMonday($request->query->getString('week'));
        $sunday = $monday->modify('+6 days');

        // Загружаем записи недели и раскладываем по дате
        $byDate = [];
        if (null !== $selectedPolygon) {
            foreach ($analyticsRepository->findByPolygonAndDateRange($selectedPolygon, $monday, $sunday) as $record) {
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

        // Итог за неделю: числовые — сумма, текстовые — число дней с отметкой
        $totals = [];
        foreach (TkoMetrics::METRICS as $metric) {
            $totals[$metric['key']] = 'num' === $metric['type']
                ? $this->normalizeNumber((string) $sums[$metric['key']])
                : (string) $counts[$metric['key']];
        }

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
        $monthEnd = $month->modify('last day of this month')->setTime(0, 0);

        // Календарные недели Пн–Вс, пересекающие месяц (крайние недели целиком)
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
                    'polygon_id' => $selectedPolygon?->getId(),
                    'week' => $cursor->format('Y-m-d'),
                ]),
            ];
        }

        $buckets = null !== $selectedPolygon
            ? $analyticsRepository->aggregateByPolygon($selectedPolygon->getId(), $firstMonday, $lastMonday->modify('+6 days'), 'week')
            : [];

        [$columns, $totals] = $this->fillColumns($columns, $buckets);

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
                    'polygon_id' => $selectedPolygon?->getId(),
                    'month' => $monthStart->format('Y-m-d'),
                ]),
            ];
        }

        $buckets = null !== $selectedPolygon
            ? $analyticsRepository->aggregateByPolygon($selectedPolygon->getId(), $yearStart, $yearEnd, 'month')
            : [];

        [$columns, $totals] = $this->fillColumns($columns, $buckets);

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

        // Недельные агрегаты по всем полигонам за выбранную неделю
        $byPolygon = [];
        foreach ($analyticsRepository->aggregateWeeklyByPolygon($monday, $sunday) as $row) {
            $byPolygon[(int) $row['polygon_id']] = $row;
        }

        // Строка на каждый активный полигон + итог по всем (числовые суммируются, текстовые — дни с отметкой)
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

    #[Route('/analytics/tko/save', name: 'app_analytics_tko_save', methods: ['POST'])]
    public function save(
        Request $request,
        PolygonRepository $polygonRepository,
        AnalyticsTKORepository $analyticsRepository,
        EntityManagerInterface $em,
    ): Response {
        $polygonId = $request->request->getInt('polygon_id');
        $week = $request->request->getString('week');
        $dateStr = $request->request->getString('date');

        if (!$this->isCsrfTokenValid('analytics_tko_save', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');

            return $this->redirectToTko($polygonId, $week);
        }

        $polygon = $polygonId > 0 ? $polygonRepository->find($polygonId) : null;
        if (null === $polygon) {
            throw $this->createNotFoundException('Полигон не найден.');
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateStr);
        if (false === $date) {
            $this->addFlash('error', 'Некорректная дата.');

            return $this->redirectToTko($polygonId, $week);
        }

        $record = $analyticsRepository->findOneByPolygonAndDate($polygon, $date);
        $isNew = null === $record;

        $hasValue = false;
        $pending = [];
        foreach (TkoMetrics::METRICS as $metric) {
            $raw = trim($request->request->getString($metric['key']));
            $value = null;

            if ('' !== $raw) {
                if ('num' === $metric['type']) {
                    $normalized = str_replace([' ', ','], ['', '.'], $raw);
                    if (!is_numeric($normalized)) {
                        $this->addFlash('error', sprintf('«%s»: «%s» — не число.', $metric['label'], $raw));

                        return $this->redirectToTko($polygonId, $week);
                    }
                    $value = $normalized;
                } else {
                    $value = $raw;
                }
                $hasValue = true;
            }

            // Откладываем запись значений до момента, когда решим создавать ли строку
            $metric['value'] = $value;
            $pending[] = $metric;
        }

        // Не создаём пустую строку
        if ($isNew && !$hasValue) {
            $this->addFlash('warning', 'Нет данных для сохранения.');

            return $this->redirectToTko($polygonId, $week);
        }

        if ($isNew) {
            $record = new AnalyticsTKO();
            $record->setPolygon($polygon);
            $record->setReportDate($date);
            $user = $this->getUser();
            if ($user instanceof User) {
                $record->setCreatedBy($user);
            }
        }

        foreach ($pending as $metric) {
            $record->{$this->setter($metric['key'])}($metric['value']);
        }

        $em->persist($record);
        $em->flush();

        $this->addFlash('success', sprintf('Сохранено: %s, %s.', $polygon->getName(), $date->format('d.m.Y')));

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
     * @param list<\App\Entity\Polygon\Polygon> $polygons
     */
    private function resolvePolygon(Request $request, PolygonRepository $polygonRepository, array $polygons): ?object
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
}
