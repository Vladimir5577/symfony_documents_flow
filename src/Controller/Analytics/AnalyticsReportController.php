<?php

declare(strict_types=1);

namespace App\Controller\Analytics;

use App\Entity\Analytics\AnalyticsReport;
use App\Entity\Organization\AbstractOrganization;
use App\Enum\Analytics\AnalyticsReportStatus;
use App\Repository\Analytics\AnalyticsBoardRepository;
use App\Service\Analytics\CreateReportService;
use App\Service\Analytics\FillReportValueService;
use App\Service\Analytics\SubmitReportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AnalyticsReportController extends AbstractController
{
    #[Route('/analytics/report', name: 'app_analytics_report')]
    public function index(
        CreateReportService $reportService,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $organization = $user->getOrganization();
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        if (!$organization && !$isAdmin) {
            return $this->render('analytics/report/index_no_org.html.twig', [
                'active_tab' => 'analytics_reports',
            ]);
        }

        // Получаем доски, назначенные этой организации (для админа — все)
        if ($isAdmin) {
            $orgBoards = $em->getRepository(\App\Entity\Analytics\AnalyticsOrganizationBoard::class)->findAll();
            $reports = $em->getRepository(AnalyticsReport::class)->findBy([], ['createdAt' => 'DESC']);
        } else {
            $orgBoardRepo = $em->getRepository(\App\Entity\Analytics\AnalyticsOrganizationBoard::class);
            $orgBoards = $orgBoardRepo->findBy(['organization' => $organization]);
            $reports = $em->getRepository(AnalyticsReport::class)->findBy(
                ['organization' => $organization],
                ['createdAt' => 'DESC']
            );
        }

        return $this->render('analytics/report/index.html.twig', [
            'orgBoards' => $orgBoards,
            'reports' => $reports,
            'active_tab' => 'analytics_reports',
        ]);
    }

    #[Route('/analytics/report/new', name: 'app_analytics_report_new')]
    public function new(
        Request $request,
        CsrfTokenManagerInterface $csrf,
        CreateReportService $reportService,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $organization = $user->getOrganization();
        if (!$organization) {
            throw $this->createAccessDeniedException('Вам не назначена организация.');
        }

        // Список досок, назначенных организации, для которых ещё нет отчёта за период
        $orgBoardRepo = $em->getRepository(\App\Entity\Analytics\AnalyticsOrganizationBoard::class);
        $orgBoards = $orgBoardRepo->findBy(['organization' => $organization]);

        // Фильтруем: только те доски, у которых есть published-версия и нет существующего отчёта
        $availableBoards = [];
        foreach ($orgBoards as $orgBoard) {
            $board = $orgBoard->getBoard();
            $hasPublished = false;
            foreach ($board->getBoardVersions() as $v) {
                if ($v->getStatus()->value === 'published') {
                    $hasPublished = true;
                    break;
                }
            }

            if (!$hasPublished) {
                continue;
            }

            $availableBoards[] = $orgBoard;
        }

        if ($request->isMethod('POST')) {
            if (!$csrf->isTokenValid(new CsrfToken('report_new', $request->request->getString('_token')))) {
                $this->addFlash('error', 'Неверный CSRF-токен.');
                return $this->redirectToRoute('app_analytics_report_new');
            }

            $boardId = $request->request->getInt('board_id');
            if (!$boardId) {
                $this->addFlash('error', 'Выберите доску.');
                return $this->redirectToRoute('app_analytics_report_new');
            }

            try {
                $report = $reportService->createReportForBoard($organization, $boardId, $user);
                $this->addFlash('success', 'Отчёт создан. Заполните значения метрик.');
                return $this->redirectToRoute('app_analytics_report_fill', ['id' => $report->getId()]);
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('analytics/report/new.html.twig', [
            'orgBoards' => $availableBoards,
            'active_tab' => 'analytics_reports',
        ]);
    }

    #[Route('/analytics/report/{id}/fill', name: 'app_analytics_report_fill')]
    public function fill(
        int $id,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        CreateReportService $reportService,
        FillReportValueService $fillService,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $report = $reportService->findByIdForUser($id, $user);
        if (!$report) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

        if ($report->getStatus() !== AnalyticsReportStatus::Draft) {
            $this->addFlash('error', 'Отчёт не является черновиком и не может быть отредактирован.');
            return $this->redirectToRoute('app_analytics_report');
        }

        // Проверяем период не закрыт
        if ($report->getPeriod() && $report->getPeriod()->isClosed()) {
            $this->addFlash('error', 'Период закрыт. Редактирование запрещено.');
            return $this->redirectToRoute('app_analytics_report');
        }

        if ($request->isMethod('POST')) {
            if (!$csrf->isTokenValid(new CsrfToken('report_fill_' . $id, $request->request->getString('_token')))) {
                $this->addFlash('error', 'Неверный CSRF-токен.');
                return $this->redirectToRoute('app_analytics_report_fill', ['id' => $id]);
            }

            $values = $request->request->all('values') ?? [];

            try {
                $fillService->fillValues($report, $values, $user);
                $this->addFlash('success', 'Значения сохранены.');
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('app_analytics_report_fill', ['id' => $id]);
        }

        // Собираем текущие значения для шаблона
        $currentValues = [];
        foreach ($report->getValues() as $v) {
            $mid = $v->getBoardVersionMetric()->getId();
            if ($v->getValueNumber() !== null) {
                $currentValues[$mid] = $v->getValueNumber();
            } elseif ($v->getValueText() !== null) {
                $currentValues[$mid] = $v->getValueText();
            } elseif ($v->getValueBool() !== null) {
                $currentValues[$mid] = $v->getValueBool() ? '1' : '0';
            }
        }

        return $this->render('analytics/report/fill.html.twig', [
            'report' => $report,
            'currentValues' => $currentValues,
            'active_tab' => 'analytics_reports',
        ]);
    }

    #[Route('/analytics/report/{id}/submit', name: 'app_analytics_report_submit', methods: ['POST'])]
    public function submit(
        int $id,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        CreateReportService $reportService,
        SubmitReportService $submitService,
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $report = $reportService->findByIdForUser($id, $user);
        if (!$report) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

        if (!$csrf->isTokenValid(new CsrfToken('report_submit_' . $id, $request->request->getString('_token')))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('app_analytics_report_fill', ['id' => $id]);
        }

        try {
            $submitService->submit($report);
            $this->addFlash('success', 'Отчёт отправлен на утверждение.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_analytics_report_fill', ['id' => $id]);
    }
}
