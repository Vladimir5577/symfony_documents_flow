<?php

declare(strict_types=1);

namespace App\Controller\Analytics;

use App\Enum\Analytics\AnalyticsPeriodType;
use App\Service\Analytics\PeriodService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AnalyticsAdminPeriodController extends AbstractController
{
    #[Route('/analytics/admin/period', name: 'app_analytics_admin_period')]
    public function index(PeriodService $periodService): Response
    {
        $periods = $periodService->findAll();

        return $this->render('analytics/admin/period/index.html.twig', [
            'periods' => $periods,
            'active_tab' => 'analytics_periods',
        ]);
    }

    #[Route('/analytics/admin/period/create', name: 'app_analytics_admin_period_create', methods: ['POST'])]
    public function create(
        Request $request,
        CsrfTokenManagerInterface $csrf,
        PeriodService $periodService,
    ): Response {
        if (!$csrf->isTokenValid(new CsrfToken('period_create', $request->request->getString('_token')))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('app_analytics_admin_period');
        }

        $typeRaw = $request->request->getString('period_type', 'weekly');
        $type = AnalyticsPeriodType::tryFrom($typeRaw) ?? AnalyticsPeriodType::Weekly;

        try {
            match ($type) {
                AnalyticsPeriodType::Daily => $this->createDailyPeriod($request, $periodService),
                AnalyticsPeriodType::Weekly => $this->createWeeklyPeriod($request, $periodService),
                AnalyticsPeriodType::Monthly => $this->createMonthlyPeriod($request, $periodService),
            };
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_analytics_admin_period');
    }

    #[Route('/analytics/admin/period/generate', name: 'app_analytics_admin_period_generate', methods: ['POST'])]
    public function generate(
        Request $request,
        CsrfTokenManagerInterface $csrf,
        PeriodService $periodService,
    ): Response {
        if (!$csrf->isTokenValid(new CsrfToken('period_generate', $request->request->getString('_token')))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('app_analytics_admin_period');
        }

        $typeRaw = $request->request->getString('period_type', 'weekly');
        $type = AnalyticsPeriodType::tryFrom($typeRaw) ?? AnalyticsPeriodType::Weekly;
        $count = max(1, min(52, $request->request->getInt('count', 4)));

        try {
            $generated = $periodService->generateUpcomingPeriods($type, $count);
            $this->addFlash('success', 'Создано периодов: ' . $generated);
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_analytics_admin_period');
    }

    #[Route('/analytics/admin/period/{id}/close', name: 'app_analytics_admin_period_close', methods: ['POST'])]
    public function close(
        int $id,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        PeriodService $periodService,
    ): Response {
        if (!$csrf->isTokenValid(new CsrfToken('period_close_' . $id, $request->request->getString('_token')))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('app_analytics_admin_period');
        }

        try {
            $periodService->close($id);
            $this->addFlash('success', 'Период закрыт.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_analytics_admin_period');
    }

    #[Route('/analytics/admin/period/{id}/open', name: 'app_analytics_admin_period_open', methods: ['POST'])]
    public function open(
        int $id,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        PeriodService $periodService,
    ): Response {
        if (!$csrf->isTokenValid(new CsrfToken('period_open_' . $id, $request->request->getString('_token')))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('app_analytics_admin_period');
        }

        try {
            $periodService->open($id);
            $this->addFlash('success', 'Период открыт.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_analytics_admin_period');
    }

    private function createDailyPeriod(Request $request, PeriodService $periodService): void
    {
        $dateStr = $request->request->getString('period_date');
        if ($dateStr === '') {
            throw new \RuntimeException('Укажите дату.');
        }
        $date = new \DateTimeImmutable($dateStr);
        $periodService->createByDate($date);
        $this->addFlash('success', 'Период создан: ' . $date->format('d.m.Y'));
    }

    private function createWeeklyPeriod(Request $request, PeriodService $periodService): void
    {
        $isoYear = $request->request->getInt('iso_year');
        $isoWeek = $request->request->getInt('iso_week');

        if ($isoYear < 2020 || $isoYear > 2099) {
            throw new \RuntimeException('Некорректный год.');
        }
        if ($isoWeek < 1 || $isoWeek > 53) {
            throw new \RuntimeException('Некорректный номер недели (1-53).');
        }

        $periodService->createByIsoWeek($isoYear, $isoWeek);
        $this->addFlash('success', 'Период создан: ' . $isoYear . '-W' . str_pad((string) $isoWeek, 2, '0', STR_PAD_LEFT));
    }

    private function createMonthlyPeriod(Request $request, PeriodService $periodService): void
    {
        $year = $request->request->getInt('year');
        $month = $request->request->getInt('month');

        if ($year < 2020 || $year > 2099) {
            throw new \RuntimeException('Некорректный год.');
        }
        if ($month < 1 || $month > 12) {
            throw new \RuntimeException('Некорректный месяц (1-12).');
        }

        $periodService->createByMonth($year, $month);
        $monthNames = [1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель', 5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь'];
        $this->addFlash('success', 'Период создан: ' . $monthNames[$month] . ' ' . $year);
    }
}
