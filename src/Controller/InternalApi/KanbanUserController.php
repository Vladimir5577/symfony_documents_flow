<?php

declare(strict_types=1);

namespace App\Controller\InternalApi;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
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

    #[Route('/users', name: 'internal_api_kanban_users', methods: ['GET'])]
    public function getUsers(Request $request): JsonResponse
    {
        $apiKey = $this->getParameter('kanban_internal_api_key');
        $providedKey = $request->headers->get('X-API-Key');

        if ($apiKey === null || $providedKey !== $apiKey) {
            return $this->json(['error' => 'Access Denied'], Response::HTTP_FORBIDDEN);
        }

        $idsParam = $request->query->get('ids', '');
        if ($idsParam === '') {
            return $this->json([]);
        }

        $ids = explode(',', $idsParam);
        $ids = array_filter(array_map('intval', $ids));

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
