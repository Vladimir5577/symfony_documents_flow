<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Controller\SpaApi\SpaApiError;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;

/**
 * Приводит ответ POST /spa/api/login_check к формату остального SPA API.
 *
 * Lexik отдаёт `{code, message}` — на весь /spa/api это было единственное место
 * с таким форматом, остальные ответы об ошибке говорят `['error' => код]`.
 *
 * Заодно чинится статус тротлинга: у TooManyLoginAttemptsAuthenticationException
 * getCode() = 0, а Lexik берёт код исключения только из диапазона 400–499 и иначе
 * ставит 401 — поэтому «слишком много попыток» приезжало с кодом «неверный пароль».
 *
 * Событие рождается только в лексиковском AuthenticationFailureHandler, который
 * подключён единственной строкой security.yaml как failure_handler для json_login.
 * Обновление refresh-токена (gesdinet) и протухший JWT кидают свои события —
 * их формат ответа не меняется.
 */
#[AsEventListener(event: 'lexik_jwt_authentication.on_authentication_failure')]
final class SpaApiLoginFailureListener
{
    public function __invoke(AuthenticationFailureEvent $event): void
    {
        // Других исключений здесь не бывает: expose_security_errors по умолчанию
        // none, и Symfony схлопывает «нет такого пользователя» и статусы аккаунта
        // в BadCredentialsException.
        [$error, $status] = match (true) {
            $event->getException() instanceof TooManyLoginAttemptsAuthenticationException
                => [SpaApiError::TOO_MANY_LOGIN_ATTEMPTS, Response::HTTP_TOO_MANY_REQUESTS],
            default
                => [SpaApiError::INVALID_CREDENTIALS, Response::HTTP_UNAUTHORIZED],
        };

        $event->setResponse(new JsonResponse(['error' => $error], $status));
    }
}
