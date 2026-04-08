<?php

declare(strict_types=1);

namespace App\Controller\Analytics;

use App\Entity\Analytics\AnalyticsBoardVersionMetric;
use App\Enum\Analytics\AnalyticsBoardVersionStatus;
use App\Repository\Analytics\AnalyticsMetricRepository;
use App\Service\Analytics\BoardService;
use App\Service\Analytics\CloneBoardVersionService;
use App\Service\Analytics\PublishBoardVersionService;
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
                $boardService->create(
                    name: $request->request->getString('name'),
                    description: $request->request->getString('description') ?: null,
                );
                $this->addFlash('success', 'Доска создана. Первая draft-версия создана автоматически.');
                return $this->redirectToRoute('app_analytics_admin_board');
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('analytics/admin/board/new.html.twig', [
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

    #[Route('/analytics/admin/board/{boardId}/version/{versionId}/edit', name: 'app_analytics_admin_board_version_edit')]
    public function version_edit(
        int $boardId,
        int $versionId,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        BoardService $boardService,
        AnalyticsMetricRepository $metricRepository,
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

        if ($version->getStatus() === AnalyticsBoardVersionStatus::Archived) {
            $this->addFlash('error', 'Нельзя редактировать архивную версию.');
            return $this->redirectToRoute('app_analytics_admin_board_show', ['id' => $boardId]);
        }

        if ($request->isMethod('POST') && $request->request->has('save_metrics')) {
            if (!$csrf->isTokenValid(new CsrfToken('version_edit_' . $versionId, $request->request->getString('_token')))) {
                $this->addFlash('error', 'Неверный CSRF-токен.');
                return $this->redirectToRoute('app_analytics_admin_board_version_edit', ['boardId' => $boardId, 'versionId' => $versionId]);
            }

            // Удаляем все текущие метрики версии
            foreach ($version->getVersionMetrics()->toArray() as $vm) {
                $boardService->removeVersionMetric($vm);
            }

            // Добавляем выбранные метрики из request
            $selectedMetrics = $request->request->all('metrics') ?? [];
            $position = 1;

            foreach ($selectedMetrics as $metricIdStr => $data) {
                $metricId = (int) $metricIdStr;
                // Пропускаем метрики где чекбокс не отмечен (enabled не отправлен)
                if (empty($data['enabled'])) {
                    continue;
                }
                $metric = $metricRepository->find($metricId);
                if (!$metric) {
                    continue;
                }

                $vm = new AnalyticsBoardVersionMetric();
                $vm->setBoardVersion($version);
                $vm->setMetric($metric);
                $vm->setPosition($position++);
                $vm->setIsRequired(!empty($data['is_required']));
                $version->addVersionMetric($vm);
            }

            $boardService->flush();
            $this->addFlash('success', 'Состав метрик сохранён.');
            return $this->redirectToRoute('app_analytics_admin_board_version_edit', ['boardId' => $boardId, 'versionId' => $versionId]);
        }

        $allMetrics = $metricRepository->findAll();

        return $this->render('analytics/admin/board/version_edit.html.twig', [
            'board' => $board,
            'version' => $version,
            'allMetrics' => $allMetrics,
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
            $this->addFlash('success', 'Создана новая версия v' . $newVersion->getVersionNumber() . ' (draft).');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_analytics_admin_board_show', ['id' => $id]);
    }

    #[Route('/analytics/admin/board/version/{versionId}/publish', name: 'app_analytics_admin_board_publish', methods: ['POST'])]
    public function publish(
        int $versionId,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        BoardService $boardService,
        PublishBoardVersionService $publishService,
    ): Response {
        $version = null;
        $boards = $boardService->findAll();
        foreach ($boards as $board) {
            foreach ($board->getBoardVersions() as $v) {
                if ($v->getId() === $versionId) {
                    $version = $v;
                    break;
                }
            }
            if ($version) {
                break;
            }
        }

        if (!$version) {
            throw $this->createNotFoundException('Версия не найдена.');
        }

        if (!$csrf->isTokenValid(new CsrfToken('version_publish_' . $versionId, $request->request->getString('_token')))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('app_analytics_admin_board_show', ['id' => $version->getBoard()->getId()]);
        }

        try {
            $publishService->publish($version);
            $this->addFlash('success', 'Версия v' . $version->getVersionNumber() . ' опубликована.');
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
            if ($version->getStatus() === AnalyticsBoardVersionStatus::Published) {
                $this->addFlash('error', 'Нельзя удалить опублированную версию.');
            } else {
                $boardService->removeVersion($version);
                $boardService->flush();
                $this->addFlash('success', 'Версия удалена.');
            }
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
