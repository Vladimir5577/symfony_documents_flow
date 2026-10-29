<?php

declare(strict_types=1);

namespace App\Tests\Controller\Document;

use App\Controller\ApiExternal\CitizenAppeal\CitizenAppealController;
use App\Controller\ApiExternal\ContractApplication\ContractApplicationController;
use App\Service\ApiExternal\CitizenAppeal\CitizenAppealApiService;
use App\Service\ApiExternal\CitizenAppeal\CitizenAppealPdfService;
use App\Service\ApiExternal\ContractApplication\ContractApplicationApiService;
use App\Service\ContractApplication\ContractApplicationPdfService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

final class SessionCsrfTest extends KernelTestCase
{
    public static function updates(): iterable
    {
        foreach (['citizen_appeal', 'contract_application'] as $type) {
            yield $type . ' missing' => [$type, null];
            yield $type . ' invalid' => [$type, 'invalid'];
            yield $type . ' valid' => [$type, 'valid'];
        }
    }

    #[DataProvider('updates')]
    public function testSessionUpdateRequiresTokenBeforeExternalRequest(string $type, ?string $token): void
    {
        $request = Request::create('/' . str_replace('_', '-', $type) . '/7/update', 'POST', ['status' => 'NEW']);
        $request->headers->set('Origin', 'https://other.example');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);
        $tokens = new CsrfTokenManager(null, new SessionTokenStorage($stack));
        if ($token !== null) {
            $request->request->set('_token', $token === 'valid' ? $tokens->getToken($type . '_update_7')->getValue() : $token);
        }
        $calls = 0;
        $http = new MockHttpClient(function (string $method) use (&$calls) {
            ++$calls;
            self::assertSame('PATCH', $method);
            return new MockResponse('{}', ['http_code' => 200]);
        });
        if ($type === 'citizen_appeal') {
            $api = new CitizenAppealApiService($http, 'https://api.example', 'test');
            $controller = new CitizenAppealController($api, new CitizenAppealPdfService($api));
        } else {
            $api = new ContractApplicationApiService($http, 'https://api.example', 'test');
            $controller = new ContractApplicationController($api, new ContractApplicationPdfService($api));
        }
        $container = new Container();
        $container->set('security.csrf.token_manager', $tokens);
        $container->set('request_stack', $stack);
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/return');
        $container->set('router', $router);
        $controller->setContainer($container);
        if ($token === 'valid') {
            self::assertSame(302, $controller->update(7, $request)->getStatusCode());
            self::assertSame(1, $calls);
        } else {
            try {
                $controller->update(7, $request);
                self::fail('Session mutation without a valid CSRF token was accepted.');
            } catch (AccessDeniedException) {
                self::assertSame(0, $calls);
            }
        }
    }
}
