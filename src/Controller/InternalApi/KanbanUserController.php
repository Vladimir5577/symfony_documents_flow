<?php

declare(strict_types=1);

namespace App\Controller\InternalApi;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/internal/kanban')]
final class KanbanUserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    /** Go-клиент батчит id десятками; потолок защищает от выгрузки всего каталога одним GET. */
    private const MAX_IDS = 200;

    #[Route('/users', name: 'internal_api_kanban_users', methods: ['GET'])]
    public function getUsers(Request $request, LoggerInterface $logger): JsonResponse
    {
        $apiKey = (string) $this->getParameter('kanban_internal_api_key');
        $providedKey = (string) ($request->headers->get('X-API-Key') ?? '');

        // Пустой ключ — реальная дыра (пустой заголовок X-API-Key проходил бы
        // сравнение), дефолт из .env.example — тоже. Отказываем всем, а не
        // «работаем как получится», и пишем в лог, чтобы это заметили на деплое.
        if ($apiKey === '' || $apiKey === 'change-me') {
            $logger->critical('KANBAN_INTERNAL_API_KEY не задан или оставлен дефолтным — внутренний API закрыт.');

            return $this->json(['error' => 'Internal API is not configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // hash_equals — сравнение за постоянное время (BE-15 / SEC-13).
        if (!hash_equals($apiKey, $providedKey)) {
            return $this->json(['error' => 'Access Denied'], Response::HTTP_FORBIDDEN);
        }

        $idsParam = (string) $request->query->get('ids', '');
        if ($idsParam === '') {
            return $this->json([]);
        }

        // Сначала дедупликация, потом потолок: Go-сервис собирает id авторов
        // по карточкам доски с повторами, и считать их до unique — ложные 400.
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsParam)))));
        if (count($ids) > self::MAX_IDS) {
            return $this->json(['error' => sprintf('Too many ids (max %d)', self::MAX_IDS)], Response::HTTP_BAD_REQUEST);
        }

        if ($ids === []) {
            return $this->json([]);
        }

        $users = $this->userRepository->findBy(['id' => $ids]);

        $result = array_map(static function (User $user) {
            return [
                'id' => $user->getId(),
                'login' => $user->getLogin(),
                'lastname' => $user->getLastname(),
                'firstname' => $user->getFirstname(),
                'patronymic' => $user->getPatronymic(),
                'avatar_name' => $user->getAvatarName(),
                'deleted_at' => $user->getDeletedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }, $users);

        return $this->json($result);
    }
}
