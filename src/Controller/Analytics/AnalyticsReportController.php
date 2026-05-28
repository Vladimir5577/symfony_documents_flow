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
use App\Service\Analytics\VersionMetricTreeBuilder;
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
     * - только с активной версией;
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
            if ($board->getActiveVersion() === null) {
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
        VersionMetricTreeBuilder $metricTreeBuilder,
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

        $activeVersion = $board->getActiveVersion();
        if (!$activeVersion) {
            $this->addFlash('error', 'У выбранной доски нет активной версии.');
            return $this->redirectToRoute('app_analytics_report_new');
        }

        $nowMoscow = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow'));
        $periodRepo = $em->getRepository(AnalyticsPeriod::class);

        $currentPeriod = $periodRepo->findOneBy([
            'type' => $board->getPeriodType(),
            'startDate' => $this->currentPeriodStartDate($board->getPeriodType(), $nowMoscow),
        ]);
        $currentIsoLabel = $currentPeriod?->getDisplayLabel() ?? $nowMoscow->format('d.m.Y');

        if ($request->isMethod('POST')) {
            if (!$csrf->isTokenValid(new CsrfToken('report_fill_new_' . $boardId, $request->request->getString('_token')))) {
                $this->addFlash('error', 'Неверный CSRF-токен.');
                return $this->redirectToRoute('app_analytics_report_fill_new', ['board_id' => $boardId]);
            }

            $values = $request->request->all('values') ?? [];
            $notes = $this->decodeNotesPayload($request->request->all('notes') ?? []);

            $hasAnyValue = false;
            foreach ($values as $value) {
                if ($value !== '' && $value !== null) {
                    $hasAnyValue = true;
                    break;
                }
            }
            $hasAnyNotes = false;
            foreach ($notes as $tree) {
                if ($tree !== []) {
                    $hasAnyNotes = true;
                    break;
                }
            }
            if (!$hasAnyValue && !$hasAnyNotes) {
                $this->addFlash('error', 'Введите хотя бы одно значение или описание перед сохранением.');
                return $this->redirectToRoute('app_analytics_report_fill_new', ['board_id' => $boardId]);
            }

            $submitAction = $request->request->getString('submit_action', 'draft');

            try {
                $report = $reportService->createReportForBoard($ownerOrganization, $boardId, $user);
                $fillService->fillValues($report, $values, $user, notes: $notes);

                if ($submitAction === 'confirm' || $submitAction === 'approve' || $submitAction === 'submit') {
                    $approveService->confirm($report);
                    $this->addFlash('success', 'Отчёт сохранён и подтверждён.');

                    return $this->redirectToRoute('app_analytics_report');
                }

                $this->addFlash('success', 'Отчёт создан как черновик, значения сохранены.');

                return $this->redirectToRoute('app_analytics_report_fill', ['id' => $report->getId()]);
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());
                return $this->redirectToRoute('app_analytics_report_fill_new', ['board_id' => $boardId]);
            }
        }

        $versionMetricEntries = [];
        foreach ($activeVersion->getVersionMetrics() as $vm) {
            $metric = $vm->getMetric();
            if (!$metric || $metric->getId() === null) {
                continue;
            }
            $versionMetricEntries[] = [
                'metric' => $metric,
                'versionMetric' => $vm,
                'data' => [
                    'position' => $vm->getPosition(),
                    'is_required' => $vm->isRequired(),
                    'parent_metric_id' => $vm->getParent()?->getMetric()?->getId(),
                ],
            ];
        }

        return $this->render('analytics/report/fill_new.html.twig', [
            'board' => $board,
            'boardVersion' => $activeVersion,
            'versionMetricsFlat' => $metricTreeBuilder->flatten($versionMetricEntries),
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
        VersionMetricTreeBuilder $metricTreeBuilder,
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
            || $report->getStatus() !== AnalyticsReportStatus::Confirmed;

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
            $notes = $this->decodeNotesPayload($request->request->all('notes') ?? []);

            try {
                $wasConfirmed = $report->getStatus() === AnalyticsReportStatus::Confirmed;
                $fillService->fillValues($report, $values, $user, $isAdmin, $notes);

                if ($isAdmin && $wasConfirmed) {
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
        $currentNotes = [];
        foreach ($report->getValues() as $v) {
            $mid = $v->getBoardVersionMetric()->getId();
            if ($v->getValueNumber() !== null) {
                $currentValues[$mid] = $v->getValueNumber();
            } elseif ($v->getValueText() !== null) {
                $currentValues[$mid] = $v->getValueText();
            } elseif ($v->getValueBool() !== null) {
                $currentValues[$mid] = $v->getValueBool() ? '1' : '0';
            }
            $json = $v->getValueJson();
            if (is_array($json) && $json !== []) {
                $currentNotes[$mid] = $json;
            }
        }

        $versionMetricEntries = [];
        foreach ($report->getBoardVersion()->getVersionMetrics() as $vm) {
            $metric = $vm->getMetric();
            if (!$metric || $metric->getId() === null) {
                continue;
            }
            $versionMetricEntries[] = [
                'metric' => $metric,
                'versionMetric' => $vm,
                'data' => [
                    'position' => $vm->getPosition(),
                    'is_required' => $vm->isRequired(),
                    'parent_metric_id' => $vm->getParent()?->getMetric()?->getId(),
                ],
            ];
        }

        $availablePeriods = [];
        $takenPeriodIds = [];
        if ($isAdmin && $report->getBoard()) {
            $availablePeriods = $em->getRepository(AnalyticsPeriod::class)->findBy(
                ['type' => $report->getBoard()->getPeriodType()],
                ['startDate' => 'DESC'],
            );

            $takenReports = $em->getRepository(AnalyticsReport::class)->findBy([
                'organization' => $report->getOrganization(),
                'board'        => $report->getBoard(),
            ]);
            foreach ($takenReports as $r) {
                $p = $r->getPeriod();
                if ($p && $r->getId() !== $report->getId()) {
                    $takenPeriodIds[$p->getId()] = true;
                }
            }
        }

        return $this->render('analytics/report/fill.html.twig', [
            'report' => $report,
            'currentValues' => $currentValues,
            'currentNotes' => $currentNotes,
            'canEdit' => $canEdit,
            'isAdmin' => $isAdmin,
            'availablePeriods' => $availablePeriods,
            'takenPeriodIds' => $takenPeriodIds,
            'versionMetricsFlat' => $metricTreeBuilder->flatten($versionMetricEntries),
            'active_tab' => 'analytics_reports',
        ]);
    }

    #[Route('/analytics/report/{id}/change-period', name: 'app_analytics_report_change_period', methods: ['POST'])]
    public function changePeriod(
        int $id,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        CreateReportService $reportService,
        RecalculateAggregatesService $recalculateService,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Изменение периода доступно только администратору.');
        }

        $report = $reportService->findByIdForUser($id, $user);
        if (!$report) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

        if (!$csrf->isTokenValid(new CsrfToken('report_period_change_' . $id, $request->request->getString('_token')))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('app_analytics_report_fill', ['id' => $id]);
        }

        $newPeriodId = $request->request->getInt('period_id');
        if ($newPeriodId <= 0) {
            $this->addFlash('error', 'Не выбран период.');
            return $this->redirectToRoute('app_analytics_report_fill', ['id' => $id]);
        }

        $oldPeriod = $report->getPeriod();
        if ($oldPeriod && $oldPeriod->getId() === $newPeriodId) {
            $this->addFlash('error', 'Этот период уже выбран.');
            return $this->redirectToRoute('app_analytics_report_fill', ['id' => $id]);
        }

        $newPeriod = $em->getRepository(AnalyticsPeriod::class)->find($newPeriodId);
        if (!$newPeriod) {
            $this->addFlash('error', 'Выбранный период не найден.');
            return $this->redirectToRoute('app_analytics_report_fill', ['id' => $id]);
        }

        $board = $report->getBoard();
        if ($board && $newPeriod->getType() !== $board->getPeriodType()) {
            $this->addFlash('error', 'Тип периода не совпадает с типом доски.');
            return $this->redirectToRoute('app_analytics_report_fill', ['id' => $id]);
        }

        $duplicate = $em->getRepository(AnalyticsReport::class)->findOneBy([
            'organization' => $report->getOrganization(),
            'board'        => $report->getBoard(),
            'period'       => $newPeriod,
        ]);
        if ($duplicate && $duplicate->getId() !== $report->getId()) {
            $this->addFlash('error', 'На этот период у организации уже есть отчёт.');
            return $this->redirectToRoute('app_analytics_report_fill', ['id' => $id]);
        }

        $report->setPeriod($newPeriod);

        try {
            $em->flush();
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Не удалось сохранить период: ' . $e->getMessage());
            return $this->redirectToRoute('app_analytics_report_fill', ['id' => $id]);
        }

        if ($report->getStatus() === AnalyticsReportStatus::Confirmed) {
            $recalculateService->recalculateForScope($oldPeriod, $report->getOrganization());
            $recalculateService->recalculateForScope($newPeriod, $report->getOrganization());
            $this->addFlash('success', 'Период изменён, агрегированная аналитика пересчитана.');
        } else {
            $this->addFlash('success', 'Период изменён.');
        }

        return $this->redirectToRoute('app_analytics_report_fill', ['id' => $id]);
    }

    #[Route('/analytics/report/{id}/view', name: 'app_analytics_report_view')]
    public function view(
        int $id,
        CreateReportService $reportService,
        VersionMetricTreeBuilder $metricTreeBuilder,
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
        $currentNotes = [];
        foreach ($report->getValues() as $v) {
            $mid = $v->getBoardVersionMetric()->getId();
            if ($v->getValueNumber() !== null) {
                $currentValues[$mid] = $v->getValueNumber();
            } elseif ($v->getValueText() !== null) {
                $currentValues[$mid] = $v->getValueText();
            } elseif ($v->getValueBool() !== null) {
                $currentValues[$mid] = $v->getValueBool() ? '1' : '0';
            }
            $json = $v->getValueJson();
            if (is_array($json) && $json !== []) {
                $currentNotes[$mid] = $json;
            }
        }

        $versionMetricEntries = [];
        foreach ($report->getBoardVersion()->getVersionMetrics() as $vm) {
            $metric = $vm->getMetric();
            if (!$metric || $metric->getId() === null) {
                continue;
            }
            $versionMetricEntries[] = [
                'metric' => $metric,
                'versionMetric' => $vm,
                'data' => [
                    'position' => $vm->getPosition(),
                    'is_required' => $vm->isRequired(),
                    'parent_metric_id' => $vm->getParent()?->getMetric()?->getId(),
                ],
            ];
        }

        return $this->render('analytics/report/view.html.twig', [
            'report' => $report,
            'currentValues' => $currentValues,
            'currentNotes' => $currentNotes,
            'versionMetricsFlat' => $metricTreeBuilder->flatten($versionMetricEntries),
            'active_tab' => 'analytics_reports',
        ]);
    }

    #[Route('/analytics/report/{id}/confirm', name: 'app_analytics_report_confirm', methods: ['POST'])]
    public function confirm(
        int $id,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        CreateReportService $reportService,
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

        if (!$csrf->isTokenValid(new CsrfToken('report_confirm_' . $id, $request->request->getString('_token')))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('app_analytics_report_fill', ['id' => $id]);
        }

        try {
            $approveService->confirm($report);
            $this->addFlash('success', 'Отчёт подтверждён.');
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
            $wasConfirmed = $report->getStatus() === AnalyticsReportStatus::Confirmed;
            $reportPeriod = $report->getPeriod();
            $reportOrganization = $report->getOrganization();
            $em->remove($report);
            $em->flush();

            if ($wasConfirmed) {
                $recalculateService->recalculateForScope($reportPeriod, $reportOrganization);
                $this->addFlash('success', 'Агрегированная аналитика пересчитана.');
            }

            $this->addFlash('success', 'Отчёт удалён.');
            return $this->redirectToRoute('app_analytics_report');
        }

        $isAuthor = $report->getCreatedBy()?->getId() === $user->getId();
        $canDeleteNotConfirmed = $report->getStatus() !== AnalyticsReportStatus::Confirmed
            && $isManager
            && $isAuthor
            && $user->getOrganization()
            && $this->isOrganizationInHierarchy($user->getOrganization(), $report->getOrganization());

        if (!$canDeleteNotConfirmed) {
            $this->addFlash('error', 'Недостаточно прав для удаления этого отчёта.');
            return $this->redirectToRoute('app_analytics_report');
        }

        $em->remove($report);
        $em->flush();

        $this->addFlash('success', 'Отчёт удалён.');
        return $this->redirectToRoute('app_analytics_report');
    }

    /**
     * Декодирует payload notes из POST: каждый элемент приходит JSON-строкой.
     * Битый JSON или не-массив на верхнем уровне → [] для этой метрики.
     *
     * @param array<int|string, mixed> $raw
     *
     * @return array<int, array<int, mixed>>
     */
    private function decodeNotesPayload(array $raw): array
    {
        $decoded = [];
        foreach ($raw as $metricIdRaw => $jsonRaw) {
            $metricId = (int) $metricIdRaw;
            if ($metricId <= 0 || !is_string($jsonRaw) || $jsonRaw === '') {
                continue;
            }
            try {
                $tree = json_decode($jsonRaw, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!is_array($tree)) {
                continue;
            }
            $decoded[$metricId] = $tree;
        }
        return $decoded;
    }

    private function currentPeriodStartDate(AnalyticsPeriodType $type, \DateTimeImmutable $now): \DateTimeImmutable
    {
        return match ($type) {
            AnalyticsPeriodType::Daily => $now->setTime(0, 0, 0),
            AnalyticsPeriodType::Weekly => (new \DateTimeImmutable())->setISODate(
                (int) $now->format('o'),
                (int) $now->format('W'),
            ),
            AnalyticsPeriodType::Monthly => new \DateTimeImmutable(sprintf('%s-%s-01', $now->format('Y'), $now->format('m'))),
        };
    }
}
