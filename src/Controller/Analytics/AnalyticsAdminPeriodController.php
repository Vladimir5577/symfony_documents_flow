<?php

declare(strict_types=1);

namespace App\Controller\Analytics;

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

        $isoYear = $request->request->getInt('iso_year');
        $isoWeek = $request->request->getInt('iso_week');

        if ($isoYear < 2020 || $isoYear > 2099) {
            $this->addFlash('error', 'Некорректный год.');
            return $this->redirectToRoute('app_analytics_admin_period');
        }
        if ($isoWeek < 1 || $isoWeek > 53) {
            $this->addFlash('error', 'Некорректный номер недели (1-53).');
            return $this->redirectToRoute('app_analytics_admin_period');
        }

        try {
            $periodService->createByIsoWeek($isoYear, $isoWeek);
            $this->addFlash('success', 'Период создан: ' . $isoYear . '-W' . str_pad((string) $isoWeek, 2, '0', STR_PAD_LEFT));
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

        try {
            $count = $periodService->generateUpcomingWeeks();
            $this->addFlash('success', 'Создано периодов: ' . $count);
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
}
