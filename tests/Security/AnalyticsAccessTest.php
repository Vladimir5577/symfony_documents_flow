<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\Analytics\AnalyticsDashboardController;
use App\Controller\SpaApi\Analytics\CitizenAppealsAnalyticsController;
use App\Controller\SpaApi\Analytics\ClientsDepartmentAnalyticsController;
use App\Controller\SpaApi\Analytics\FinancialAnalyticsController;
use App\Controller\SpaApi\Analytics\HrAnalyticsController;
use App\Controller\SpaApi\Analytics\MechanicsAnalyticsController;
use App\Entity\Analytics\AnalyticsBoard;
use App\Entity\Analytics\AnalyticsReport;
use App\Entity\Organization\AbstractOrganization;
use App\Entity\User\Role;
use App\Entity\User\User;
use App\Entity\User\UserRole;
use App\Repository\Analytics\AnalyticsAggregatedDataRepository;
use App\Repository\Analytics\AnalyticsOrganizationRepository;
use App\Repository\Analytics\AnalyticsReportRepository;
use App\Repository\Analytics\AnalyticsReportValueRepository;
use App\Repository\Organization\OrganizationRepository;
use App\Security\AnalyticsReportAccess;
use App\Service\Analytics\CitizenAppealsReportTreeService;
use App\Service\Analytics\ClientsDepartmentReportTreeService;
use App\Service\Analytics\DashboardDataService;
use App\Service\Analytics\FinanceReportTreeService;
use App\Service\Analytics\HrReportTreeService;
use App\Service\Analytics\MechanicsReportTreeService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class AnalyticsAccessTest extends KernelTestCase
{
    private Connection $connection;
    private OrganizationRepository $organizations;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine')->getConnection();
        $this->connection->beginTransaction();

        // PostgreSQL временные таблицы скрывают реальные только в этом соединении.
        // Откат удаляет фикстуры и сами таблицы, не затрагивая данные приложения.
        $tables = [
            'organization' => 'id int, short_name text',
            'analytics_boards' => 'id int, category text, belongs_to_role_id int',
            'analytics_periods' => 'id int, start_date date, end_date date',
            'analytics_reports' => 'id int, board_id int, board_version_id int, organization_id int, period_id int, status text, created_at timestamp, updated_at timestamp',
            'analytics_metrics' => 'id int, business_key text, name text, unit text, category text',
            'analytics_board_version_metrics' => 'id int, board_version_id int, metric_id int, parent_id int, position int',
            'analytics_report_values' => 'report_id int, board_version_metric_id int, value_number numeric, value_json text',
        ];
        foreach ($tables as $name => $columns) {
            $this->connection->executeStatement("CREATE TEMP TABLE $name ($columns) ON COMMIT DROP");
        }
        $this->connection->insert('organization', ['id' => 1, 'short_name' => 'Fixture']);
        $this->connection->insert('analytics_periods', ['id' => 1, 'start_date' => '2026-01-05', 'end_date' => '2026-01-11']);
        $this->connection->insert('analytics_periods', ['id' => 2, 'start_date' => '2026-01-12', 'end_date' => '2026-01-18']);

        foreach (['finance', 'hr', 'mechanics', 'clients_department', 'citizen_appeal'] as $index => $category) {
            // Один разрешённый отчёт и чужой отчёт в той же неделе;
            // ещё один чужой отчёт — в более свежей неделе (проверка пагинации).
            foreach ([1, 2, 3] as $variant) {
                $id = $index * 10 + $variant;
                $this->connection->insert('analytics_boards', ['id' => $id, 'category' => $category, 'belongs_to_role_id' => $variant === 3 ? null : ($variant === 1 ? 10 : 20)]);
                $this->connection->insert('analytics_reports', [
                    'id' => $id, 'board_id' => $id, 'board_version_id' => $id,
                    'organization_id' => 1, 'period_id' => $variant === 3 ? 2 : 1,
                    'status' => 'confirmed', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01',
                ]);
                $this->connection->insert('analytics_metrics', ['id' => $id, 'business_key' => 'finance_debit', 'name' => 'Fixture', 'unit' => null, 'category' => $category]);
                $this->connection->insert('analytics_board_version_metrics', ['id' => $id, 'board_version_id' => $id, 'metric_id' => $id, 'parent_id' => null, 'position' => 1]);
                $this->connection->insert('analytics_report_values', [
                    'report_id' => $id, 'board_version_metric_id' => $id,
                    'value_number' => $variant === 1 ? 123 : 987654,
                    'value_json' => $variant === 1 ? '[{"note":"visible"}]' : '[{"note":"secret-marker"}]',
                ]);
            }
        }

        $organization = $this->createMock(AbstractOrganization::class);
        $organization->method('getId')->willReturn(1);
        $this->organizations = $this->createMock(OrganizationRepository::class);
        $this->organizations->method('findOrganizationWithChildrenIds')->willReturn([1]);
        $this->organizations->method('findAllParentOrganizations')->willReturn([$organization]);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testApiTreesListsAndDashboardUseReportPolicy(): void
    {
        $this->authenticate('ROLE_USER', 10);
        $policy = self::getContainer()->get(AnalyticsReportAccess::class);
        self::assertTrue($policy->canAccessReport($this->report(10)));
        self::assertFalse($policy->canAccessReport($this->report(20)));
        self::assertFalse($policy->canAccessReport(new AnalyticsReport()));

        $cases = [
            [FinancialAnalyticsController::class, FinanceReportTreeService::class, 'financeReports'],
            [HrAnalyticsController::class, HrReportTreeService::class, 'reports'],
            [MechanicsAnalyticsController::class, MechanicsReportTreeService::class, 'reports'],
            [ClientsDepartmentAnalyticsController::class, ClientsDepartmentReportTreeService::class, 'reports'],
            [CitizenAppealsAnalyticsController::class, CitizenAppealsReportTreeService::class, 'reports'],
        ];
        $values = self::getContainer()->get(AnalyticsReportValueRepository::class);
        $reports = self::getContainer()->get(AnalyticsReportRepository::class);

        foreach ($cases as [$controllerClass, $serviceClass, $method]) {
            $service = new $serviceClass($values, $reports, $this->organizations);
            $controller = new $controllerClass();
            $controller->setContainer(self::getContainer());
            foreach ([0, 1] as $orgId) {
                $request = new Request(['org_id' => $orgId, 'limit' => 1, 'per_page' => 1, 'from' => '2026-01-01', 'to' => '2026-02-01']);
                $content = $controller->$method($request, $service)->getContent();
                $tree = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
                self::assertCount(1, $tree['availableWeeks']);
                self::assertSame('2026-01-05', $tree['weeks'][0]['startDate']);
                self::assertCount(1, $tree['weeks'][0]['reports']);
                self::assertStringContainsString('visible', $content);
                self::assertStringNotContainsString('secret-marker', $content);
                self::assertStringNotContainsString('987654', $content);

                $list = json_decode($controller->reportsList($request, $service)->getContent(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(1, $list['total']);
                self::assertCount(1, $list['items']);
            }
        }

        $dashboard = new DashboardDataService(
            self::getContainer()->get(AnalyticsAggregatedDataRepository::class),
            $this->createMock(AnalyticsOrganizationRepository::class),
            $this->organizations,
            $values,
        );
        $controller = new AnalyticsDashboardController();
        $controller->setContainer(self::getContainer());
        foreach (['week', 'month'] as $scale) {
            $content = $controller->data(new Request(['org_id' => 0, 'scale' => $scale]), $dashboard)->getContent();
            self::assertStringContainsString('123', $content);
            self::assertStringNotContainsString('987654', $content);
        }
    }

    public function testFullAccessAndMissingRole(): void
    {
        $reports = self::getContainer()->get(AnalyticsReportRepository::class);
        $values = self::getContainer()->get(AnalyticsReportValueRepository::class);
        $policy = self::getContainer()->get(AnalyticsReportAccess::class);

        foreach (['ROLE_ADMIN', 'ROLE_ANALYTIC'] as $role) {
            $this->authenticate($role, 30);
            self::assertTrue($policy->canAccessReport($this->report(20)));
            self::assertTrue($policy->canAccessReport(new AnalyticsReport()));
            self::assertSame(3, $reports->findConfirmedListByCategory('finance', [1], null, null, 1, 20)['total']);
            self::assertCount(2, $reports->findAvailableWeeksByCategory('finance', [1], null, null));
            self::assertCount(3, $values->findReportsWithMetricTree([1], 'finance'));
            self::assertCount(3, $values->findValuesByCategory([1], 'finance'));
        }

        $this->authenticate('ROLE_USER', 30);
        self::assertFalse($policy->canAccessReport($this->report(10)));
        self::assertSame([], $reports->findAvailableWeeksByCategory('finance', [1], null, null));
        self::assertSame(0, $reports->findConfirmedListByCategory('finance', [1], null, null, 1, 20)['total']);
        self::assertSame([], $values->findReportsWithMetricTree([1], 'finance'));
        self::assertSame([], $values->findValuesByCategory([1], 'finance'));

        self::getContainer()->get('security.token_storage')->setToken(null);
        self::assertSame([], $policy->getAllowedRoleIds());
        self::assertSame([], $values->findValuesByCategory([1], 'finance'));
    }

    private function authenticate(string $name, int $id): void
    {
        $role = new Role($name);
        (new \ReflectionProperty(Role::class, 'id'))->setValue($role, $id);
        $user = (new User())->setLogin('analytics-fixture');
        $user->getRolesRel()->add(new UserRole($user, $role));
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', [$name]));
    }

    private function report(int $roleId): AnalyticsReport
    {
        $role = new Role();
        (new \ReflectionProperty(Role::class, 'id'))->setValue($role, $roleId);

        return (new AnalyticsReport())->setBoard((new AnalyticsBoard())->setBelongsToRole($role));
    }
}
