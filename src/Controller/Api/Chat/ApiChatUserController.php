<?php

namespace App\Controller\Api\Chat;

use App\Entity\Organization\Department;
use App\Repository\User\UserRepository;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/chat/users')]
final class ApiChatUserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly CacheManager $imagineCacheManager,
    ) {
    }

    #[Route('/{id}/profile', name: 'api_chat_user_profile', methods: ['GET'])]
    public function profile(int $id): JsonResponse
    {
        $user = $this->userRepo->find($id);
        if (!$user) {
            return $this->json(['error' => 'User not found'], 404);
        }

        $worker = $user->getWorker();
        $org = $user->getOrganization();

        $departmentName = null;
        $organizationName = null;

        if ($org instanceof Department) {
            $departmentName = $org->getName();
            $organizationName = $org->getRootOrganization()->getName();
        } elseif ($org) {
            $organizationName = $org->getName();
        }

        return $this->json([
            'id' => $user->getId(),
            'lastname' => $user->getLastname(),
            'firstname' => $user->getFirstname(),
            'patronymic' => $user->getPatronymic(),
            'avatar' => $user->getAvatarName()
                ? $this->imagineCacheManager->getBrowserPath(
                    $user->getId() . '/' . $user->getAvatarName(),
                    'avatar_medium'
                )
                : null,
            'phone' => $user->getPhone(),
            'email' => $user->getEmail(),
            'profession' => $worker ? $worker->getProfession() : null,
            'department' => $departmentName,
            'organization' => $organizationName,
            'last_seen_at' => $user->getLastSeenAt()?->format('c'),
        ]);
    }
}
