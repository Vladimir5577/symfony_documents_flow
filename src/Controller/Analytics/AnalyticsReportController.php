<?php

declare(strict_types=1);

namespace App\Controller\Analytics;

use App\Entity\Analytics\AnalyticsPeriod;
use App\Entity\Analytics\AnalyticsReport;
use App\Entity\Organization\AbstractOrganization;
use App\Enum\Analytics\AnalyticsPeriodType;
use App\Enum\Analytics\AnalyticsReportStatus;
use App\Repository\Analytics\AnalyticsBoardRepository;
use App\Service\Analytics\CreateReportService;
use App\Service\Analytics\FillReportValueService;
use App\Service\Analytics\ApproveReportService;
use App\Service\Analytics\RecalculateAggregatesService;
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
    /**
     * Собрать id текущей организации и всех её родителей (вверх по дереву).
     *
     * @return int[]
     */
    private function getOrganizationHierarchyIds(AbstractOrganization $organization): array
    {
        $ids = [];
        $current = $organization;

        while ($current !== null) {
            $id = $current->getId();
            if ($id === null) {
                break;
            }

            $ids[] = $id;
            $current = $current->getParent();
        }

        return array_values(array_unique($ids));
    }

    private function isOrganizationInHierarchy(AbstractOrganization $userOrganization, ?AbstractOrganization $reportOrganization): bool
    {
        if ($reportOrganization === null || $reportOrganization->getId() === null) {
            return false;
        }

        return in_array($reportOrganization->getId(), $this->getOrganizationHierarchyIds($userOrganization), true);
    }

    /**
     * Возвращает доступные доски для организации пользователя:
     * - только с published-версией;
     * - если доска назначена на нескольких уровнях, выбираем ближайший к пользователю.
     *
     * @return array<int, \App\Entity\Analytics\AnalyticsOrganizationBoard> key = board_id
     */
    private function getAvailableBoardMapForOrganization(AbstractOrganization $organization, EntityManagerInterface $em): array
    {
        $orgBoardRepo = $em->getRepository(\App\Entity\Analytics\AnalyticsOrganizationBoard::class);
        $orgIds = $this->getOrganizationHierarchyIds($organization);
        $orgBoards = $orgBoardRepo->createQueryBuilder('ob')
            ->join('ob.organization', 'o')
            ->andWhere('o.id IN (:orgIds)')
            ->setParameter('orgIds', $orgIds)
            ->orderBy('ob.id', 'DESC')
            ->getQuery()
            ->getResult();

        $availableBoardMap = [];
        $orgRank = array_flip($orgIds); // меньше индекс = ближе к пользователю
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

            $boardIdKey = $board->getId();
            $ownerOrgId = $orgBoard->getOrganization()?->getId();
            if ($boardIdKey === null || $ownerOrgId === null) {
                continue;
            }

            $rank = $orgRank[$ownerOrgId] ?? PHP_INT_MAX;
            if (!isset($availableBoardMap[$boardIdKey]) || $rank < $availableBoardMap[$boardIdKey]['rank']) {
                $availableBoardMap[$boardIdKey] = [
                    'rank' => $rank,
                    'orgBoard' => $orgBoard,
                ];
            }
        }

        return array_map(static fn(array $row) => $row['orgBoard'], $availableBoardMap);
    }

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
            $orgIds = $this->getOrganizationHierarchyIds($organization);
            $orgBoards = $orgBoardRepo->createQueryBuilder('ob')
                ->join('ob.organization', 'o')
                ->andWhere('o.id IN (:orgIds)')
                ->setParameter('orgIds', $orgIds)
                ->orderBy('ob.id', 'DESC')
                ->getQuery()
                ->getResult();
            $reports = $reportService->findByUser($user);
        }

        return $this->render('analytics/report/index.html.twig', [
            'orgBoards' => $orgBoards,
            'reports' => $reports,
            'active_tab' => 'analytics_reports',
        ]);
    }

    #[Route('/analytics/report/new', name: 'app_analytics_report_new')]
    public function new(
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
        $availableBoards = array_values($this->getAvailableBoardMapForOrganization($organization, $em));

        return $this->render('analytics/report/new.html.twig', [
            'orgBoards' => $availableBoards,
            'active_tab' => 'analytics_reports',
        ]);
    }

    #[Route('/analytics/report/fill-new', name: 'app_analytics_report_fill_new')]
    public function fillNew(
        Request $request,
        CsrfTokenManagerInterface $csrf,
        CreateReportService $reportService,
        FillReportValueService $fillService,
        ApproveReportService $approveService,
        SubmitReportService $submitService,
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

        $availableBoardMap = $this->getAvailableBoardMapForOrganization($organization, $em);
        $boardId = (int) $request->query->get('board_id', $request->request->getInt('board_id'));
        if ($boardId <= 0 || !isset($availableBoardMap[$boardId])) {
            $this->addFlash('error', 'Выберите доску для заполнения отчёта.');
            return $this->redirectToRoute('app_analytics_report_new');
        }

        $orgBoard = $availableBoardMap[$boardId];
        $board = $orgBoard->getBoard();
        $ownerOrganization = $orgBoard->getOrganization();
        if (!$board || !$ownerOrganization) {
            $this->addFlash('error', 'Не удалось определить доску или организацию назначения.');
            return $this->redirectToRoute('app_analytics_report_new');
        }

        $publishedVersion = null;
        foreach ($board->getBoardVersions() as $v) {
            if ($v->getStatus()->value === 'published') {
                $publishedVersion = $v;
                break;
            }
        }
        if (!$publishedVersion) {
            $this->addFlash('error', 'У выбранной доски нет опубликованной версии.');
            return $this->redirectToRoute('app_analytics_report_new');
        }

        $nowMoscow = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow'));
        $periodRepo = $em->getRepository(AnalyticsPeriod::class);

        $currentPeriod = match ($board->getPeriodType()) {
            AnalyticsPeriodType::Daily => $periodRepo->findOneBy(['type' => AnalyticsPeriodType::Daily, 'periodDate' => $nowMoscow->setTime(0, 0, 0)]),
            AnalyticsPeriodType::Weekly => $this->findWeeklyPeriod($periodRepo, $nowMoscow),
            AnalyticsPeriodType::Monthly => $periodRepo->findOneBy(['type' => AnalyticsPeriodType::Monthly, 'year' => (int) $nowMoscow->format('Y'), 'month' => (int) $nowMoscow->format('n')]),
        };
        $currentIsoLabel = $currentPeriod?->getDisplayLabel() ?? $nowMoscow->format('d.m.Y');

        if ($request->isMethod('POST')) {
            if (!$csrf->isTokenValid(new CsrfToken('report_fill_new_' . $boardId, $request->request->getString('_token')))) {
                $this->addFlash('error', 'Неверный CSRF-токен.');
                return $this->redirectToRoute('app_analytics_report_fill_new', ['board_id' => $boardId]);
            }

            $values = $request->request->all('values') ?? [];
            $hasAnyValue = false;
            foreach ($values as $value) {
                if ($value !== '' && $value !== null) {
                    $hasAnyValue = true;
                    break;
                }
            }
            if (!$hasAnyValue) {
                $this->addFlash('error', 'Введите хотя бы одно значение перед сохранением.');
                return $this->redirectToRoute('app_analytics_report_fill_new', ['board_id' => $boardId]);
            }

            $submitAction = $request->request->getString('submit_action', 'draft');

            try {
                $report = $reportService->createReportForBoard($ownerOrganization, $boardId, $user);
                $fillService->fillValues($report, $values, $user);

                if ($submitAction === 'approve' || $submitAction === 'submit') {
                    $submitService->submit($report);
                    $approveService->approve($report, $user);
                    $this->addFlash('success', 'Отчёт сохранён и утверждён.');

                    return $this->redirectToRoute('app_analytics_report');
                }

                $this->addFlash('success', 'Отчёт создан как черновик, значения сохранены.');

                return $this->redirectToRoute('app_analytics_report_fill', ['id' => $report->getId()]);
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());
                return $this->redirectToRoute('app_analytics_report_fill_new', ['board_id' => $boardId]);
            }
        }

        return $this->render('analytics/report/fill_new.html.twig', [
            'board' => $board,
            'boardVersion' => $publishedVersion,
            'ownerOrganization' => $ownerOrganization,
            'currentPeriod' => $currentPeriod,
            'currentIsoLabel' => $currentIsoLabel,
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
        RecalculateAggregatesService $recalculateService,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        $report = $reportService->findByIdForUser($id, $user);
        if (!$report) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

        $canEdit = $isAdmin
            || (
                $report->getStatus() !== AnalyticsReportStatus::Approved
                && (!$report->getPeriod() || !$report->getPeriod()->isClosed())
            );

        if ($request->isMethod('POST')) {
            if (!$canEdit) {
                $this->addFlash('error', 'Редактирование этого отчёта запрещено.');
                return $this->redirectToRoute('app_analytics_report_fill', ['id' => $id]);
            }

            if (!$csrf->isTokenValid(new CsrfToken('report_fill_' . $id, $request->request->getString('_token')))) {
                $this->addFlash('error', 'Неверный CSRF-токен.');
                return $this->redirectToRoute('app_analytics_report_fill', ['id' => $id]);
            }

            $values = $request->request->all('values') ?? [];

            try {
                $wasApproved = $report->getStatus() === AnalyticsReportStatus::Approved;
                $fillService->fillValues($report, $values, $user, $isAdmin);

                if ($isAdmin && $wasApproved) {
                    $recalculateService->recalculateForScope($report->getPeriod(), $report->getOrganization());
                    $this->addFlash('success', 'Агрегированная аналитика пересчитана.');
                }

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
            'canEdit' => $canEdit,
            'active_tab' => 'analytics_reports',
        ]);
    }

    #[Route('/analytics/report/{id}/view', name: 'app_analytics_report_view')]
    public function view(
        int $id,
        CreateReportService $reportService,
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $report = $reportService->findByIdForUser($id, $user);
        if (!$report) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

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

        return $this->render('analytics/report/view.html.twig', [
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
        ApproveReportService $approveService,
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

        $submitAction = $request->request->getString('submit_action', 'submit');

        try {
            if ($submitAction === 'approve') {
                if (!$this->isGranted('ROLE_MANAGER')) {
                    throw new \RuntimeException('Недостаточно прав для утверждения отчёта.');
                }

                $submitService->submit($report);
                $approveService->approve($report, $user);
                $this->addFlash('success', 'Отчёт отправлен и утверждён.');
            } else {
                $submitService->submit($report);
                $this->addFlash('success', 'Отчёт отправлен на утверждение.');
            }
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_analytics_report_fill', ['id' => $id]);
    }

    #[Route('/analytics/report/{id}/delete', name: 'app_analytics_report_delete', methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        RecalculateAggregatesService $recalculateService,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        if (!$csrf->isTokenValid(new CsrfToken('report_delete_' . $id, $request->request->getString('_token')))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('app_analytics_report');
        }

        $report = $em->getRepository(AnalyticsReport::class)->find($id);
        if (!$report) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $isManager = $this->isGranted('ROLE_MANAGER');

        if ($isAdmin) {
            $wasApproved = $report->getStatus() === AnalyticsReportStatus::Approved;
            $reportPeriod = $report->getPeriod();
            $reportOrganization = $report->getOrganization();
            $em->remove($report);
            $em->flush();

            if ($wasApproved) {
                $recalculateService->recalculateForScope($reportPeriod, $reportOrganization);
                $this->addFlash('success', 'Агрегированная аналитика пересчитана.');
            }

            $this->addFlash('success', 'Отчёт удалён.');
            return $this->redirectToRoute('app_analytics_report');
        }

        $isAuthor = $report->getCreatedBy()?->getId() === $user->getId();
        $canDeleteNotApproved = $report->getStatus() !== AnalyticsReportStatus::Approved
            && $isManager
            && $isAuthor
            && $user->getOrganization()
            && $this->isOrganizationInHierarchy($user->getOrganization(), $report->getOrganization());

        if (!$canDeleteNotApproved) {
            $this->addFlash('error', 'Недостаточно прав для удаления этого отчёта.');
            return $this->redirectToRoute('app_analytics_report');
        }

        $em->remove($report);
        $em->flush();

        $this->addFlash('success', 'Отчёт удалён.');
        return $this->redirectToRoute('app_analytics_report');
    }

    private function findWeeklyPeriod(object $periodRepo, \DateTimeImmutable $now): ?AnalyticsPeriod
    {
        return $periodRepo->findOneBy([
            'type' => AnalyticsPeriodType::Weekly,
            'isoYear' => (int) $now->format('o'),
            'isoWeek' => (int) $now->format('W'),
        ]);
    }
}
