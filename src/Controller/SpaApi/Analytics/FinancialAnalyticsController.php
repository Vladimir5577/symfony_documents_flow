<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Analytics;

use App\Service\Analytics\FinanceReportTreeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class FinancialAnalyticsController extends AbstractController
{
    private const DEFAULT_LIMIT = 12;
    private const MAX_LIMIT = 100;
    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE = 100;
    private const DATE_REGEX = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * Финансовые отчёты в виде дерева метрик по неделям.
     * Формат:
     *   { weeks: [{ startDate, endDate, reports: [{ metric_key, name, unit, valueNumber, valueJSON, children: [...] }] }] }
     */
    #[Route('/spa/api/analytics/finance/reports', name: 'spa_api_analytics_finance_reports', methods: ['GET'])]
    public function financeReports(
        Request $request,
        FinanceReportTreeService $financeReportTreeService,
    ): JsonResponse {
        $orgId = $request->query->getInt('org_id', 0);

        $from = $this->validateDateParam($request->query->get('from'));
        $to   = $this->validateDateParam($request->query->get('to'));

        $limit  = $request->query->getInt('limit', self::DEFAULT_LIMIT);
        $offset = $request->query->getInt('offset', 0);

        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        } elseif ($limit > self::MAX_LIMIT) {
            $limit = self::MAX_LIMIT;
        }
        if ($offset < 0) {
            $offset = 0;
        }

        return $this->json(
            $financeReportTreeService->buildWeeks($orgId, $from, $to, $limit, $offset),
        );
    }

    /**
     * Плоский список подтверждённых финансовых отчётов без метрик.
     * Формат:
     *   { items: [{ id, boardId, boardVersionId, organization: {id, name}, period: {startDate, endDate}, status, createdAt, updatedAt }], page, perPage, total }
     */
    #[Route('/spa/api/analytics/finance/reports/list', name: 'spa_api_analytics_finance_reports_list', methods: ['GET'])]
    public function reportsList(
        Request $request,
        FinanceReportTreeService $financeReportTreeService,
    ): JsonResponse {
        $orgId = $request->query->getInt('org_id', 0);

        $from = $this->validateDateParam($request->query->get('from'));
        $to   = $this->validateDateParam($request->query->get('to'));

        $page    = $request->query->getInt('page', 1);
        $perPage = $request->query->getInt('per_page', self::DEFAULT_PER_PAGE);

        if ($page < 1) {
            $page = 1;
        }
        if ($perPage < 1) {
            $perPage = self::DEFAULT_PER_PAGE;
        } elseif ($perPage > self::MAX_PER_PAGE) {
            $perPage = self::MAX_PER_PAGE;
        }

        return $this->json(
            $financeReportTreeService->getAllReports($orgId, $from, $to, $page, $perPage),
        );
    }

    private function validateDateParam(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        return preg_match(self::DATE_REGEX, $value) === 1 ? $value : null;
    }
}
