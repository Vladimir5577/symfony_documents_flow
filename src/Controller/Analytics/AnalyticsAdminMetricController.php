<?php

declare(strict_types=1);

namespace App\Controller\Analytics;

use App\Entity\Analytics\AnalyticsMetric;
use App\Enum\Analytics\AnalyticsMetricAggregationType;
use App\Repository\Analytics\AnalyticsMetricRepository;
use App\Service\Analytics\AnalyticsMetricService;
use Symfony\Bridge\Twig\AppVariable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AnalyticsAdminMetricController extends AbstractController
{
    #[Route('/analytics/admin/metric', name: 'app_analytics_admin_metric_index')]
    public function index(AnalyticsMetricService $metricService): Response
    {
        $metrics = $metricService->findAll();

        return $this->render('analytics/admin/metric/index.html.twig', [
            'metrics' => $metrics,
            'active_tab' => 'analytics_metrics',
        ]);
    }

    #[Route('/analytics/admin/metric/new', name: 'app_analytics_admin_metric_new')]
    public function new(Request $request, CsrfTokenManagerInterface $csrf, AnalyticsMetricService $metricService): Response
    {
        if ($request->isMethod('POST')) {
            if (!$csrf->isTokenValid(new CsrfToken('metric_new', $request->request->getString('_token')))) {
                $this->addFlash('error', 'Неверный CSRF-токен.');
                return $this->redirectToRoute('app_analytics_admin_metric_new');
            }

            try {
                $metricService->create(
                    businessKey: $request->request->getString('business_key'),
                    name: $request->request->getString('name'),
                    type: $request->request->getString('type'),
                    unit: $request->request->getString('unit'),
                    aggregationType: AnalyticsMetricAggregationType::from($request->request->getString('aggregation_type')),
                    inputType: $request->request->getString('input_type') ?: null,
                    isActive: (bool) $request->request->get('is_active', false),
                );
                $this->addFlash('success', 'Метрика создана.');
                return $this->redirectToRoute('app_analytics_admin_metric_index');
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('analytics/admin/metric/new.html.twig', [
            'metric' => null,
            'aggregationTypes' => AnalyticsMetricAggregationType::cases(),
            'inputTypes' => ['text', 'number', 'select', 'checkbox'],
            'active_tab' => 'analytics_metrics',
        ]);
    }

    #[Route('/analytics/admin/metric/{id}/edit', name: 'app_analytics_admin_metric_edit')]
    public function edit(
        int $id,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        AnalyticsMetricService $metricService,
    ): Response {
        $metric = $metricService->findById($id);
        if (!$metric) {
            throw $this->createNotFoundException('Метрика не найдена.');
        }

        if ($request->isMethod('POST')) {
            if (!$csrf->isTokenValid(new CsrfToken('metric_edit_' . $id, $request->request->getString('_token')))) {
                $this->addFlash('error', 'Неверный CSRF-токен.');
                return $this->redirectToRoute('app_analytics_admin_metric_edit', ['id' => $id]);
            }

            try {
                $metricService->update(
                    metric: $metric,
                    businessKey: $request->request->getString('business_key'),
                    name: $request->request->getString('name'),
                    type: $request->request->getString('type'),
                    unit: $request->request->getString('unit'),
                    aggregationType: AnalyticsMetricAggregationType::from($request->request->getString('aggregation_type')),
                    inputType: $request->request->getString('input_type') ?: null,
                    isActive: (bool) $request->request->get('is_active', false),
                );
                $this->addFlash('success', 'Метрика обновлена.');
                return $this->redirectToRoute('app_analytics_admin_metric_index');
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('analytics/admin/metric/edit.html.twig', [
            'metric' => $metric,
            'aggregationTypes' => AnalyticsMetricAggregationType::cases(),
            'inputTypes' => ['text', 'number', 'select', 'checkbox'],
            'active_tab' => 'analytics_metrics',
        ]);
    }

    #[Route('/analytics/admin/metric/{id}/delete', name: 'app_analytics_admin_metric_delete', methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        AnalyticsMetricService $metricService,
    ): Response {
        $metric = $metricService->findById($id);
        if (!$metric) {
            throw $this->createNotFoundException('Метрика не найдена.');
        }

        if (!$csrf->isTokenValid(new CsrfToken('metric_delete_' . $id, $request->request->getString('_token')))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('app_analytics_admin_metric_index');
        }

        try {
            $metricService->delete($metric);
            $this->addFlash('success', 'Метрика удалена.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Не удалось удалить метрику: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_analytics_admin_metric_index');
    }
}
