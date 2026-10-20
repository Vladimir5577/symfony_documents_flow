<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\ApiExternal\HR\VacancyApplicationController;
use App\Controller\ApiExternal\HR\VacancyApplicationResumeProxyController;
use App\Controller\ApiExternal\HR\VacancyController;
use App\Controller\Organization\OrganizationController;
use App\Service\ApiExternal\Vacancy\VacancyApiService;
use App\Service\ApiExternal\VacancyApplication\VacancyApplicationApiService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class StructureHrAccessTest extends KernelTestCase
{
    public static function routeAccessProvider(): iterable
    {
        $structureRoutes = [
            ['GET', '/create_organization'],
            ['POST', '/create_organization'],
            ['GET', '/edit_organization/2'],
            ['POST', '/edit_organization/2'],
            ['POST', '/delete_organization/2'],
        ];
        foreach ($structureRoutes as [$method, $path]) {
            foreach (['ROLE_USER', 'ROLE_HR', 'ROLE_INVENTORY_MANAGER', 'ROLE_ADMIN'] as $role) {
                yield "$role $method $path" => [$method, $path, $role, $role === 'ROLE_ADMIN'];
            }
        }

        $hrRoutes = [
            ['GET', '/hr_vacancies'],
            ['GET', '/hr_vacancies/new'],
            ['GET', '/hr_vacancies/2'],
            ['GET', '/hr_vacancies/2/edit'],
            ['POST', '/hr_vacancies/create'],
            ['POST', '/hr_vacancies/2/update'],
            ['POST', '/hr_vacancies/2/publish'],
            ['GET', '/hr_vacancies_applications'],
            ['GET', '/hr_vacancies_applications/2'],
            ['GET', '/hr_vacancies_applications/2/resume'],
            ['POST', '/hr_vacancies_applications/2/update'],
        ];
        foreach ($hrRoutes as [$method, $path]) {
            foreach (['ROLE_USER', 'ROLE_HR', 'ROLE_ADMIN'] as $role) {
                yield "$role $method $path" => [$method, $path, $role, $role !== 'ROLE_USER'];
            }
        }
    }

    #[DataProvider('routeAccessProvider')]
    public function testAccessIsCheckedBeforeControllerExecution(
        string $method,
        string $path,
        string $role,
        bool $allowed,
    ): void {
        self::bootKernel();
        $container = self::getContainer();
        $user = new InMemoryUser('access-test', null, [$role]);
        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::never())->method('request');
        $vacancies = new VacancyApiService($httpClient, 'https://hr.invalid', 'test');
        $applications = new VacancyApplicationApiService($httpClient, 'https://hr.invalid', 'test');
        $controllers = [
            OrganizationController::class => new OrganizationController(),
            VacancyController::class => new VacancyController($vacancies),
            VacancyApplicationController::class => new VacancyApplicationController($applications),
            VacancyApplicationResumeProxyController::class => new VacancyApplicationResumeProxyController($applications),
        ];

        $request = Request::create($path, $method, ['parent_id' => 1]);
        $container->get('router')->getContext()->fromRequest($request); // матчинг по методу запроса, а не GET по умолчанию
        $request->attributes->add($container->get('router')->matchRequest($request));
        [$class, $action] = explode('::', $request->attributes->get('_controller'));
        $event = new ControllerArgumentsEvent(
            self::$kernel,
            [$controllers[$class], $action],
            [],
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        // Реальные маршруты, иерархия ролей и слушатель Symfony; до вызова БД и внешнего API.
        if (!$allowed) {
            $this->expectException(AccessDeniedException::class);
            $this->expectExceptionCode(403);
        }

        $container->get('event_dispatcher')->dispatch($event, KernelEvents::CONTROLLER_ARGUMENTS);
        self::assertTrue($allowed, 'Недостаточная роль прошла проверку доступа.');
    }
}
