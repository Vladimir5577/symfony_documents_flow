<?php

declare(strict_types=1);

namespace App\Controller\Analytics\TKO;

use App\Entity\Analytics\TKO\AnalyticsTKO;
use App\Entity\User\User;
use App\Repository\Analytics\TKO\AnalyticsTKORepository;
use App\Repository\Polygon\PolygonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MANAGER')]
final class AnalyticsTKOController extends AbstractController
{
    /**
     * Метрики таблицы: ключ = колонка сущности, type = num|text.
     */
    private const METRICS = [
        ['key' => 'garbage_trucks_volume',   'label' => 'Мусоровозы',            'type' => 'num'],
        ['key' => 'garbage_trucks_weight',   'label' => 'Вес ТКО мусоровозы',    'type' => 'num'],
        ['key' => 'containers_volume',       'label' => 'Контейнеры',            'type' => 'num'],
        ['key' => 'scrap_trucks_volume',     'label' => 'Ломовозы',              'type' => 'num'],
        ['key' => 'containers_scrap_weight', 'label' => 'Вес ТКО конт., ломов',  'type' => 'num'],
        ['key' => 'vegetation_volume',       'label' => 'Растительные',          'type' => 'num'],
        ['key' => 'construction_volume',     'label' => 'Строительные',          'type' => 'num'],
        ['key' => 'terminal_volume',         'label' => 'Терминал',              'type' => 'num'],
        ['key' => 'machinery_work',          'label' => 'Работа техники',        'type' => 'text'],
        ['key' => 'fire_condition',          'label' => 'Пожарное состояние',    'type' => 'text'],
        ['key' => 'irrigation',              'label' => 'Орошение',              'type' => 'text'],
    ];

    private const DOW = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

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
            foreach (self::METRICS as $metric) {
                $raw = null !== $record ? $record->{$this->getter($metric['key'])}() : null;
                $values[$metric['key']] = 'num' === $metric['type']
                    ? $this->normalizeNumber($raw)
                    : (string) ($raw ?? '');
            }

            $days[] = [
                'date' => $key,
                'dow' => self::DOW[$i],
                'short' => $date->format('d.m'),
                'values' => $values,
            ];
        }

        return $this->render('analytics/tko/index.html.twig', [
            'active_tab' => 'analytics_tko',
            'polygons' => $polygons,
            'selectedPolygon' => $selectedPolygon,
            'metrics' => self::METRICS,
            'days' => $days,
            'week' => $monday->format('Y-m-d'),
            'weekLabel' => sprintf('%s — %s', $monday->format('d.m'), $sunday->format('d.m')),
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
        foreach (self::METRICS as $metric) {
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
