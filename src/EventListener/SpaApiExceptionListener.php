<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Controller\SpaApi\SpaApiError;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Приводит непойманные HttpException под `/spa/api/` к конвенции проекта `{error: <код>}`.
 *
 * Зачем понадобился. Доменные сервисы бросают `BadRequestHttpException(SpaApiError::CODE)`,
 * и это работает только там, где контроллер сам ловит исключение (`DocumentsFlow`, `Post` —
 * приватный `jsonError()` в каждом файле). Глобального обработчика в проекте не было,
 * поэтому в модуле инвентаризации, где `catch (HttpException)` не выписан ни в одном из
 * 14 контроллеров, 52 доменных броска уходили в рендерер Symfony и до фронта доезжали
 * HTML-страницей. Фронт читает только `data.error` (`analytics_platform/src/lib/api.ts`),
 * получал пустоту — и все написанные русские тексты ошибок не показывались никогда.
 *
 * Почему слушатель, а не `try/catch` в каждом методе: контроллеров 14, методов около сотни,
 * и правка «руками в каждом» даёт огромный диф с высоким шансом пропустить ветку.
 * Слушатель закрывает класс проблемы целиком и одинаково для будущих контроллеров.
 *
 * Границы, намеренно узкие:
 *  - только пути с префиксом `/spa/api/` — SSR-страницы и Twig-часть портала не трогаем;
 *  - только `HttpExceptionInterface`, то есть ОСОЗНАННО брошенные доменные ошибки.
 *    Настоящие сбои (TypeError, ошибки драйвера БД) остаются 500 и продолжают логироваться —
 *    подменять их на аккуратный JSON нельзя, иначе поломка станет невидимой;
 *  - если ответ уже сформирован (например, слушателем Security), не вмешиваемся.
 *
 * Приоритет отрицательный: пусть сначала отработают слушатели Security и бандла JWT,
 * у них своя логика ответов на 401/403.
 */
#[AsEventListener(event: 'kernel.exception', priority: -64)]
final class SpaApiExceptionListener
{
    private const PATH_PREFIX = '/spa/api/';

    /** Код ошибки — строчные латиница/цифры/подчёркивание: ровно так объявлены константы SpaApiError. */
    private const CODE_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public function __invoke(ExceptionEvent $event): void
    {
        if ($event->getResponse() !== null) {
            return;
        }

        if (!str_starts_with($event->getRequest()->getPathInfo(), self::PATH_PREFIX)) {
            return;
        }

        $exception = $event->getThrowable();
        if (!$exception instanceof HttpExceptionInterface) {
            return;
        }

        $status = $exception->getStatusCode();

        $event->setResponse(new JsonResponse(
            ['error' => $this->errorCode($exception->getMessage(), $status)],
            $status,
            $exception->getHeaders(),
        ));
    }

    /**
     * Сервисы кладут код прямо в сообщение исключения — тогда отдаём его как есть.
     * Исключения самого фреймворка несут человеческий текст («No route found for…»),
     * его наружу пускать нельзя: фронт ждёт код, а не фразу, да и внутренности светить незачем.
     */
    private function errorCode(string $message, int $status): string
    {
        if ($message !== '' && preg_match(self::CODE_PATTERN, $message) === 1) {
            return $message;
        }

        return $status === Response::HTTP_FORBIDDEN
            ? SpaApiError::ACCESS_DENIED
            : SpaApiError::REQUEST_FAILED;
    }
}
