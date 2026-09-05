<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Realtime;

use App\Entity\User\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Подписной JWT для Mercure (FE-02 / SEC-03).
 *
 * Хаб раньше работал с директивой `anonymous`: любой, кто знал id доски или
 * чата, читал realtime-поток без входа в портал. Теперь директива снята, а
 * подписка требует JWT. EventSource не умеет слать заголовки, поэтому фронт
 * берёт токен здесь (обычным запросом с Bearer) и передаёт хабу
 * query-параметром `authorization`.
 *
 * Токен короткоживущий и только на чтение (publish пуст). Скоуп подписки — все
 * топики для любого сотрудника: это закрывает анонимный доступ; точечное
 * ограничение «только свои доски» — следующий шаг, для него нужен список
 * досок пользователя из Go-сервиса.
 */
final class MercureTokenController extends AbstractController
{
    private const TTL_SECONDS = 3600;

    #[Route('/spa/api/mercure/token', name: 'spa_api_mercure_token', methods: ['GET'])]
    public function __invoke(HubInterface $hub, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $factory = $hub->getFactory();
        if ($factory === null) {
            return $this->json(['error' => 'mercure_not_configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $token = $factory->create(
            subscribe: ['*'],
            publish: [],
            additionalClaims: [
                'exp' => new \DateTimeImmutable(sprintf('+%d seconds', self::TTL_SECONDS)),
                'sub' => (string) $user->getId(),
            ],
        );

        return $this->json([
            'token' => $token,
            'expiresIn' => self::TTL_SECONDS,
        ]);
    }
}
