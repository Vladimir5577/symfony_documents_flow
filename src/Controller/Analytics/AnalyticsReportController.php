<?php

declare(strict_types=1);

namespace App\Controller\Analytics;

use App\Entity\Analytics\AnalyticsPeriod;
use App\Entity\Analytics\AnalyticsReport;
use App\Entity\Analytics\AnalyticsOrganizationBoard;
use App\Entity\Organization\AbstractOrganization;
use App\Entity\User\User;
use App\Enum\Analytics\AnalyticsReportStatus;
use App\Repository\Analytics\AnalyticsBoardRepository;
use App\Repository\Analytics\AnalyticsReportRepository;
use App\Service\Analytics\CreateReportService;
use App\Service\Analytics\FillReportValueService;
use App\Service\Analytics\ApproveReportService;
use App\Service\Analytics\RecalculateAggregatesService;
use App\Service\Analytics\VersionMetricTreeBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

    private function hasAnalyticsFullAccess(): bool
    {
        return $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_ANALYTIC');
    }

    /**
     * Доступ к отчёту по роли: админ/аналитик — любой отчёт,
     * иначе роль доски (belongsToRole) должна входить в роли пользователя.
     */
    private function canAccessReport(User $user, AnalyticsReport $report): bool
    {
        if ($this->hasAnalyticsFullAccess()) {
            return true;
        }

        $boardRoleId = $report->getBoard()?->getBelongsToRole()?->getId();
        if ($boardRoleId === null) {
            return false;
        }

        return in_array($boardRoleId, $this->resolveUserRoleIds($user), true);
    }

    /**
     * Сбор данных отчёта для просмотра/выгрузки: текущие значения и примечания по id метрики версии.
     *
     * @return array{values: array<int, mixed>, notes: array<int, array<int, mixed>>}
     */
    private function collectReportData(AnalyticsReport $report): array
    {
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

        return ['values' => $currentValues, 'notes' => $currentNotes];
    }

    /**
     * Плоское дерево метрик версии отчёта (с полем depth).
     *
     * @return list<array{metric: mixed, versionMetric: mixed, data: array, depth: int}>
     */
    private function buildVersionMetricsFlat(AnalyticsReport $report, VersionMetricTreeBuilder $metricTreeBuilder): array
    {
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

        return $metricTreeBuilder->flatten($versionMetricEntries);
    }

    /**
     * @return int[]
     */
    private function resolveUserRoleIds(User $user): array
    {
        $ids = [];
        foreach ($user->getRolesRel() as $userRole) {
            $roleId = $userRole->getRole()?->getId();
            if ($roleId !== null) {
                $ids[] = $roleId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<int|string, AnalyticsOrganizationBoard> $orgBoards
     * @param int[] $roleIds
     *
     * @return array<int|string, AnalyticsOrganizationBoard>
     */
    private function filterOrgBoardsByRoleIds(array $orgBoards, array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        return array_filter(
            $orgBoards,
            static function (AnalyticsOrganizationBoard $orgBoard) use ($roleIds): bool {
                $boardRoleId = $orgBoard->getBoard()?->getBelongsToRole()?->getId();

                return $boardRoleId !== null && in_array($boardRoleId, $roleIds, true);
            },
        );
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

    /**
     * @return \App\Entity\Analytics\AnalyticsOrganizationBoard[]
     */
    private function getAvailableBoardsForAdmin(EntityManagerInterface $em): array
    {
        $result = [];
        foreach ($em->getRepository(\App\Entity\Analytics\AnalyticsOrganizationBoard::class)->findAll() as $orgBoard) {
            if ($orgBoard->getBoard()?->getActiveVersion() !== null) {
                $result[] = $orgBoard;
            }
        }

        return $result;
    }

    /**
     * @return array<int, \App\Entity\Analytics\AnalyticsOrganizationBoard>
     */
    private function getAvailableBoardMapForAdmin(EntityManagerInterface $em): array
    {
        $map = [];
        foreach ($this->getAvailableBoardsForAdmin($em) as $orgBoard) {
            $boardId = $orgBoard->getBoard()?->getId();
            if ($boardId !== null) {
                $map[$boardId] = $orgBoard;
            }
        }

        return $map;
    }

    #[Route('/analytics/report', name: 'app_analytics_report')]
    public function index(
        AnalyticsReportRepository $reportRepository,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $organization = $user->getOrganization();
        $isAdmin = $this->hasAnalyticsFullAccess();

        if (!$organization && !$isAdmin) {
            return $this->render('analytics/report/index_no_org.html.twig', [
                'active_tab' => 'analytics_reports',
            ]);
        }

        // Получаем доски, назначенные этой организации (для админа/аналитика — все)
        if ($isAdmin) {
            $orgBoards = $em->getRepository(\App\Entity\Analytics\AnalyticsOrganizationBoard::class)->findAll();
            $reports = $reportRepository->findForIndex();
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
            $reports = $reportRepository->findForIndex($this->resolveUserRoleIds($user));
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
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $organization = $user->getOrganization();
        $isAdmin = $this->hasAnalyticsFullAccess();

        if ($isAdmin) {
            $availableBoards = $this->getAvailableBoardsForAdmin($em);
        } elseif (!$organization) {
            throw $this->createAccessDeniedException('Вам не назначена организация.');
        } else {
            $availableBoards = array_values($this->filterOrgBoardsByRoleIds(
                $this->getAvailableBoardMapForOrganization($organization, $em),
                $this->resolveUserRoleIds($user),
            ));
        }

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
        VersionMetricTreeBuilder $metricTreeBuilder,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $organization = $user->getOrganization();
        $isAdmin = $this->hasAnalyticsFullAccess();

        if ($isAdmin) {
            $availableBoardMap = $this->getAvailableBoardMapForAdmin($em);
        } elseif (!$organization) {
            throw $this->createAccessDeniedException('Вам не назначена организация.');
        } else {
            $availableBoardMap = $this->filterOrgBoardsByRoleIds(
                $this->getAvailableBoardMapForOrganization($organization, $em),
                $this->resolveUserRoleIds($user),
            );
        }

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

        $currentPeriod = $reportService->findCurrentPeriodForBoard($board);
        $currentIsoLabel = $reportService->getCurrentPeriodDisplayLabel($board);

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

            try {
                $report = $reportService->createReportForBoard($ownerOrganization, $boardId, $user);
                $fillService->fillValues($report, $values, $user, notes: $notes);

                $this->addFlash('success', 'Отчёт создан как черновик, значения сохранены.');

                return $this->redirectToRoute('app_analytics_report');
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
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $isAdmin = $this->hasAnalyticsFullAccess();

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
                if ($wasConfirmed) {
                    $report->setStatus(AnalyticsReportStatus::Draft);
                    $report->setApprovedBy(null);
                    $report->setApprovedAt(null);
                }
                $fillService->fillValues($report, $values, $user, $isAdmin, $notes);

                if ($isAdmin && $wasConfirmed) {
                    $recalculateService->recalculateForScope($report->getPeriod(), $report->getOrganization());
                    $this->addFlash('success', 'Отчёт сохранён как черновик, агрегированная аналитика пересчитана.');
                } else {
                    $this->addFlash('success', 'Значения сохранены.');
                }
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->redirectToRoute('app_analytics_report_fill', ['id' => $id]);
            }

            return $this->redirectToRoute('app_analytics_report');
        }

        // Собираем текущие значения для шаблона
        $data = $this->collectReportData($report);
        $currentValues = $data['values'];
        $currentNotes = $data['notes'];

        $availablePeriods = [];
        $takenPeriodIds = [];
        if ($isAdmin && $report->getBoard()) {
            $availablePeriods = $reportService->getAvailablePeriodsForBoard($report->getBoard());

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
            'versionMetricsFlat' => $this->buildVersionMetricsFlat($report, $metricTreeBuilder),
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
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->hasAnalyticsFullAccess()) {
            throw $this->createAccessDeniedException('Изменение периода доступно только администратору или аналитику.');
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

        return $this->redirectToRoute('app_analytics_report');
    }

    #[Route('/analytics/report/{id}/view', name: 'app_analytics_report_view')]
    public function view(
        int $id,
        CreateReportService $reportService,
        VersionMetricTreeBuilder $metricTreeBuilder,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $report = $reportService->findById($id);
        if (!$report) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

        if (!$this->canAccessReport($user, $report)) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

        $data = $this->collectReportData($report);

        return $this->render('analytics/report/view.html.twig', [
            'report' => $report,
            'currentValues' => $data['values'],
            'currentNotes' => $data['notes'],
            'versionMetricsFlat' => $this->buildVersionMetricsFlat($report, $metricTreeBuilder),
            'active_tab' => 'analytics_reports',
        ]);
    }

    #[Route('/analytics/report/{id}/export-excel', name: 'app_analytics_report_export_excel')]
    public function exportExcel(
        int $id,
        CreateReportService $reportService,
        VersionMetricTreeBuilder $metricTreeBuilder,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $report = $reportService->findById($id);
        if (!$report) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

        if (!$this->canAccessReport($user, $report)) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

        $data = $this->collectReportData($report);
        $currentValues = $data['values'];
        $currentNotes = $data['notes'];
        $versionMetricsFlat = $this->buildVersionMetricsFlat($report, $metricTreeBuilder);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Отчёт');

        // Шапка-заголовок отчёта
        $boardName = $report->getBoard()?->getName() ?? '';
        $periodLabel = $report->getPeriod()?->getHumanLabel() ?? '—';
        $versionNumber = $report->getBoardVersion()->getVersionNumber();
        $sheet->setCellValue('A1', sprintf('%s — %s (v%s)', $boardName, $periodLabel, $versionNumber));
        $sheet->mergeCells('A1:C1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        // Заголовки таблицы
        $headerRow = 3;
        $sheet->fromArray(['Метрика', 'Ед. изм.', 'Значение'], null, 'A' . $headerRow);
        $sheet->getStyle('A' . $headerRow . ':C' . $headerRow)->getFont()->setBold(true);
        $sheet->getStyle('A' . $headerRow . ':C' . $headerRow)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E9ECEF');

        $row = $headerRow + 1;
        foreach ($versionMetricsFlat as $entry) {
            $vm = $entry['versionMetric'];
            $metric = $entry['metric'];
            $depth = $entry['depth'] ?? 0;
            $vmId = $vm->getId();

            $value = $currentValues[$vmId] ?? '';
            $type = (string) $metric->getType();

            // Значение: bool → Да/Нет/—, числа → нормализуем (без хвостовых нулей) и пишем числом, иначе текст или —
            $isNumericValue = false;
            if ($type === 'bool') { // тип не выводится отдельной колонкой, но определяет формат значения
                $displayValue = $value === '1' ? 'Да' : ($value === '0' ? 'Нет' : '—');
            } elseif ($value === '' || $value === null) {
                $displayValue = '—';
            } elseif (is_numeric($value)) {
                // Нормализация по строке (без float-погрешностей): "10.00" → "10", "2.50" → "2.5".
                $normalized = (string) $value;
                if (str_contains($normalized, '.')) {
                    $normalized = rtrim(rtrim($normalized, '0'), '.');
                }
                $displayValue = $normalized;
                $isNumericValue = true;
            } else {
                $displayValue = (string) $value;
            }

            $name = $metric->getName();
            if ($vm->isRequired()) {
                $name .= ' *';
            }

            // Визуальная вложенность: отступ по глубине + маркер «└ » для дочерних метрик.
            $depthInt = (int) $depth;
            if ($depthInt > 0) {
                $name = str_repeat('    ', $depthInt) . '└ ' . $name;
            }

            $sheet->setCellValueExplicit('A' . $row, $name, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->getStyle('A' . $row)->getAlignment()->setIndent($depthInt);
            $sheet->setCellValue('B' . $row, (string) $metric->getUnit());
            $sheet->setCellValueExplicit(
                'C' . $row,
                $displayValue,
                $isNumericValue
                    ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                    : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
            );
            $row++;

            // Примечания (дерево) — отдельными строками под метрикой
            $notes = $currentNotes[$vmId] ?? [];
            if (is_array($notes) && $notes !== []) {
                $row = $this->writeNotesRows($sheet, $notes, $row, (int) $depth + 1);
            }
        }

        foreach (['A' => 60, 'B' => 14, 'C' => 30] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $filename = sprintf('report_%d.xlsx', $id);

        $response = new StreamedResponse(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        );
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }

    /**
     * Рекурсивно выводит дерево примечаний в колонку «Значение», по строке на узел.
     * Формат узла: { key, value, children[] } — как в _metric_notes_view.html.twig.
     *
     * @param array<int, mixed> $nodes
     */
    private function writeNotesRows(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $nodes, int $row, int $indent): int
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $key = (string) ($node['key'] ?? '');
            $value = (string) ($node['value'] ?? '');
            $children = $node['children'] ?? [];

            $text = $key;
            if ($key !== '' && $value !== '') {
                $text .= ' — ';
            }
            $text .= $value;

            if ($text !== '' || (is_array($children) && $children !== [])) {
                if ($text !== '') {
                    $sheet->setCellValueExplicit('C' . $row, $text, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $sheet->getStyle('C' . $row)->getAlignment()->setIndent($indent);
                    $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setItalic(true);
                    $row++;
                }
                if (is_array($children) && $children !== []) {
                    $row = $this->writeNotesRows($sheet, $children, $row, $indent + 1);
                }
            }
        }

        return $row;
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
        if (!$user instanceof User) {
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
            $approveService->confirm($report, $user);
            $this->addFlash('success', 'Отчёт подтверждён.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_analytics_report');
        }

        return $this->redirectToRoute('app_analytics_report');
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
        if (!$user instanceof User) {
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

        $isAdmin = $this->hasAnalyticsFullAccess();
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
}
