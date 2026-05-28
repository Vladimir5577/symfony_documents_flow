<?php

declare(strict_types=1);

namespace App\Controller\Analytics;

use App\Enum\Analytics\AnalyticsCategory;
use App\Enum\Analytics\AnalyticsPeriodType;
use App\Repository\Analytics\AnalyticsBoardVersionRepository;
use App\Repository\Analytics\AnalyticsMetricRepository;
use App\Entity\Analytics\AnalyticsMetric;
use App\Service\Analytics\BoardService;
use App\Service\Analytics\CloneBoardVersionService;
use App\Service\Analytics\PublishBoardVersionService;
use App\Service\Analytics\VersionMetricTreeBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AnalyticsAdminBoardController extends AbstractController
{
    #[Route('/analytics/admin/board', name: 'app_analytics_admin_board')]
    public function index(BoardService $boardService): Response
    {
        $boards = $boardService->findAll();

        return $this->render('analytics/admin/board/index.html.twig', [
            'boards' => $boards,
            'active_tab' => 'analytics_boards',
        ]);
    }

    #[Route('/analytics/admin/board/new', name: 'app_analytics_admin_board_new')]
    public function new(Request $request, CsrfTokenManagerInterface $csrf, BoardService $boardService): Response
    {
        if ($request->isMethod('POST')) {
            if (!$csrf->isTokenValid(new CsrfToken('board_new', $request->request->getString('_token')))) {
                $this->addFlash('error', 'Неверный CSRF-токен.');
                return $this->redirectToRoute('app_analytics_admin_board_new');
            }

            try {
                $periodTypeRaw = $request->request->getString('period_type', 'weekly');
                $periodType = AnalyticsPeriodType::tryFrom($periodTypeRaw) ?? AnalyticsPeriodType::Weekly;

                $categoryRaw = $request->request->getString('category');
                $category = AnalyticsCategory::tryFrom($categoryRaw);
                if ($category === null) {
                    throw new \RuntimeException('Категория обязательна и должна быть из списка.');
                }

                $boardService->create(
                    name: $request->request->getString('name'),
                    description: $request->request->getString('description') ?: null,
                    category: $category,
                    periodType: $periodType,
                );
                $this->addFlash('success', 'Доска создана. Первая активная версия создана автоматически.');
                return $this->redirectToRoute('app_analytics_admin_board');
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('analytics/admin/board/new.html.twig', [
            'categories' => AnalyticsCategory::cases(),
            'active_tab' => 'analytics_boards',
        ]);
    }

    #[Route('/analytics/admin/board/{id}', name: 'app_analytics_admin_board_show')]
    public function show(int $id, BoardService $boardService, CloneBoardVersionService $cloneService): Response
    {
        $board = $boardService->findById($id);
        if (!$board) {
            throw $this->createNotFoundException('Доска не найдена.');
        }

        return $this->render('analytics/admin/board/show.html.twig', [
            'board' => $board,
            'active_tab' => 'analytics_boards',
        ]);
    }

    #[Route('/analytics/admin/board/{id}/edit', name: 'app_analytics_admin_board_edit')]
    public function edit(
        int $id,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        BoardService $boardService,
    ): Response {
        $board = $boardService->findById($id);
        if (!$board) {
            throw $this->createNotFoundException('Доска не найдена.');
        }

        if ($request->isMethod('POST')) {
            if (!$csrf->isTokenValid(new CsrfToken('board_edit_' . $id, $request->request->getString('_token')))) {
                $this->addFlash('error', 'Неверный CSRF-токен.');
                return $this->redirectToRoute('app_analytics_admin_board_edit', ['id' => $id]);
            }

            try {
                $periodTypeRaw = $request->request->getString('period_type', $board->getPeriodType()->value);
                $periodType = AnalyticsPeriodType::tryFrom($periodTypeRaw) ?? $board->getPeriodType();

                $categoryRaw = $request->request->getString('category', $board->getCategory()->value);
                $category = AnalyticsCategory::tryFrom($categoryRaw) ?? $board->getCategory();

                $boardService->update(
                    board: $board,
                    name: $request->request->getString('name'),
                    description: $request->request->getString('description') ?: null,
                    periodType: $periodType,
                    category: $category,
                );
                $this->addFlash('success', 'Доска обновлена.');
                return $this->redirectToRoute('app_analytics_admin_board_show', ['id' => $id]);
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('analytics/admin/board/edit.html.twig', [
            'board' => $board,
            'categories' => AnalyticsCategory::cases(),
            'can_change_category' => $boardService->canChangeCategory($board),
            'can_change_period_type' => $boardService->canChangePeriodType($board),
            'active_tab' => 'analytics_boards',
        ]);
    }

    #[Route('/analytics/admin/board/{boardId}/version/{versionId}/edit', name: 'app_analytics_admin_board_version_edit')]
    public function version_edit(
        int $boardId,
        int $versionId,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        BoardService $boardService,
        AnalyticsMetricRepository $metricRepository,
        VersionMetricTreeBuilder $metricTreeBuilder,
    ): Response {
        $board = $boardService->findById($boardId);
        if (!$board) {
            throw $this->createNotFoundException('Доска не найдена.');
        }

        $version = null;
        foreach ($board->getBoardVersions() as $v) {
            if ($v->getId() === $versionId) {
                $version = $v;
                break;
            }
        }
        if (!$version) {
            throw $this->createNotFoundException('Версия не найдена.');
        }

        if ($request->isMethod('POST') && $request->request->has('save_metrics')) {
            if (!$csrf->isTokenValid(new CsrfToken('version_edit_' . $versionId, $request->request->getString('_token')))) {
                $this->addFlash('error', 'Неверный CSRF-токен.');
                return $this->redirectToRoute('app_analytics_admin_board_version_edit', ['boardId' => $boardId, 'versionId' => $versionId]);
            }

            try {
                $selectedMetrics = $request->request->all('metrics') ?? [];
                $boardService->syncVersionMetrics($version, $selectedMetrics, $metricRepository->findAll());
                $this->addFlash('success', 'Состав метрик сохранён.');
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('app_analytics_admin_board_version_edit', ['boardId' => $boardId, 'versionId' => $versionId]);
        }

        $allMetrics = $metricRepository->findAll();

        /** @var array<int, array{position: int, is_required: bool, parent_metric_id: int|null}> $vmByMetric */
        $vmByMetric = [];
        foreach ($version->getVersionMetrics() as $vm) {
            $metricId = $vm->getMetric()?->getId();
            if ($metricId === null) {
                continue;
            }
            $parentMetricId = $vm->getParent()?->getMetric()?->getId();
            $vmByMetric[$metricId] = [
                'position' => $vm->getPosition(),
                'is_required' => $vm->isRequired(),
                'parent_metric_id' => $parentMetricId,
            ];
        }

        $selectedEntries = [];
        /** @var AnalyticsMetric[] $unselectedMetrics */
        $unselectedMetrics = [];
        foreach ($allMetrics as $metric) {
            $metricId = $metric->getId();
            if ($metricId === null) {
                continue;
            }
            if (isset($vmByMetric[$metricId])) {
                $selectedEntries[] = ['metric' => $metric, 'data' => $vmByMetric[$metricId]];
            } else {
                $unselectedMetrics[] = $metric;
            }
        }

        return $this->render('analytics/admin/board/version_edit.html.twig', [
            'board' => $board,
            'version' => $version,
            'selectedMetricsFlat' => $metricTreeBuilder->flatten($selectedEntries),
            'unselectedMetrics' => $unselectedMetrics,
            'categories' => AnalyticsCategory::cases(),
            'active_tab' => 'analytics_boards',
        ]);
    }

    #[Route('/analytics/admin/board/{id}/clone', name: 'app_analytics_admin_board_clone', methods: ['POST'])]
    public function clone(
        int $id,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        BoardService $boardService,
        CloneBoardVersionService $cloneService,
    ): Response {
        $board = $boardService->findById($id);
        if (!$board) {
            throw $this->createNotFoundException('Доска не найдена.');
        }

        if (!$csrf->isTokenValid(new CsrfToken('board_clone_' . $id, $request->request->getString('_token')))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('app_analytics_admin_board_show', ['id' => $id]);
        }

        $sourceVersionId = $request->request->getInt('source_version_id');
        $sourceVersion = null;
        foreach ($board->getBoardVersions() as $v) {
            if ($v->getId() === $sourceVersionId) {
                $sourceVersion = $v;
                break;
            }
        }

        if (!$sourceVersion) {
            $this->addFlash('error', 'Исходная версия не найдена.');
            return $this->redirectToRoute('app_analytics_admin_board_show', ['id' => $id]);
        }

        try {
            $newVersion = $cloneService->cloneFromVersion($sourceVersion);
            $this->addFlash('success', 'Создана новая неактивная версия v' . $newVersion->getVersionNumber() . '.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_analytics_admin_board_show', ['id' => $id]);
    }

    #[Route('/analytics/admin/board/version/{versionId}/publish', name: 'app_analytics_admin_board_publish', methods: ['POST'])]
    public function activate(
        int $versionId,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        BoardService $boardService,
        PublishBoardVersionService $publishService,
        AnalyticsBoardVersionRepository $versionRepository,
    ): Response {
        $version = $versionRepository->find($versionId);

        if (!$version) {
            throw $this->createNotFoundException('Версия не найдена.');
        }

        if (!$csrf->isTokenValid(new CsrfToken('version_publish_' . $versionId, $request->request->getString('_token')))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('app_analytics_admin_board_show', ['id' => $version->getBoard()->getId()]);
        }

        try {
            $publishService->activate($version);
            $this->addFlash('success', 'Версия v' . $version->getVersionNumber() . ' сделана активной.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_analytics_admin_board_show', ['id' => $version->getBoard()->getId()]);
    }

    #[Route('/analytics/admin/board/{id}/version/{versionId}/delete', name: 'app_analytics_admin_board_version_delete', methods: ['POST'])]
    public function deleteVersion(
        int $id,
        int $versionId,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        BoardService $boardService,
    ): Response {
        $board = $boardService->findById($id);
        if (!$board) {
            throw $this->createNotFoundException('Доска не найдена.');
        }

        $version = null;
        foreach ($board->getBoardVersions() as $v) {
            if ($v->getId() === $versionId) {
                $version = $v;
                break;
            }
        }
        if (!$version) {
            throw $this->createNotFoundException('Версия не найдена.');
        }

        if (!$csrf->isTokenValid(new CsrfToken('version_delete_' . $versionId, $request->request->getString('_token')))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('app_analytics_admin_board_show', ['id' => $id]);
        }

        try {
            $boardService->removeVersion($version);
            $boardService->flush();
            $this->addFlash('success', 'Версия удалена.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Не удалось удалить версию: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_analytics_admin_board_show', ['id' => $id]);
    }

    #[Route('/analytics/admin/board/{id}/delete', name: 'app_analytics_admin_board_delete', methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        BoardService $boardService,
    ): Response {
        $board = $boardService->findById($id);
        if (!$board) {
            throw $this->createNotFoundException('Доска не найдена.');
        }

        if (!$csrf->isTokenValid(new CsrfToken('board_delete_' . $id, $request->request->getString('_token')))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('app_analytics_admin_board');
        }

        try {
            $boardService->delete($board);
            $this->addFlash('success', 'Доска удалена.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Не удалось удалить доску: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_analytics_admin_board');
    }
}
