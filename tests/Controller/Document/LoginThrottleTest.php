<?php

declare(strict_types=1);

namespace App\Tests\Controller\Document;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\EventListener\LoginThrottlingListener;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

final class LoginThrottleTest extends KernelTestCase
{
    public function testLegacyAndSpaLoginsShareTheAttemptBudget(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $property = new \ReflectionProperty(LoginThrottlingListener::class, 'limiter');
        $legacy = $property->getValue($container->get('security.listener.login_throttling.main'));
        $spa = $property->getValue($container->get('security.listener.login_throttling.spa_api'));
        self::assertSame($spa, $legacy);
        $login = 'throttle-test-' . bin2hex(random_bytes(8));
        $request = Request::create('/spa/api/login_check', 'POST', [], [], [], ['REMOTE_ADDR' => '192.0.2.45']);
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $login);
        $legacyRequest = Request::create('/login', 'POST', [], [], [], ['REMOTE_ADDR' => '192.0.2.45']);
        $legacyRequest->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $login);
        try {
            $spa->reset($request);
            for ($attempt = 0; $attempt < 5; ++$attempt) {
                self::assertTrue(($attempt % 2 === 0 ? $spa : $legacy)->consume($attempt % 2 === 0 ? $request : $legacyRequest)->isAccepted());
            }
            self::assertFalse($legacy->consume($legacyRequest)->isAccepted());
            self::assertFalse($spa->consume($request)->isAccepted());
        } finally {
            $spa->reset($request);
        }
    }
}
