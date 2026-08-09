<?php

namespace App\Controller\Api\Chat;

use App\Entity\Organization\Department;
use App\Repository\User\UserRepository;
use App\Service\User\UserAvatarUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/chat/users')]
final class ApiChatUserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly UserAvatarUrlGenerator $avatarUrlGenerator,
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

        // Карточка собеседника в чате — это ФИО, должность и подразделение.
        // Телефон, почта и время последней активности к переписке отношения
        // не имеют и отдаются на тех же условиях, что в каталоге сотрудников.
        $canSeePersonnelData = $this->isGranted('ROLE_MANAGER');

        $profile = [
            'id' => $user->getId(),
            'lastname' => $user->getLastname(),
            'firstname' => $user->getFirstname(),
            'patronymic' => $user->getPatronymic(),
            'avatar' => $this->avatarUrlGenerator->getAvatarUrl($user),
            'profession' => $worker ? $worker->getProfession() : null,
            'department' => $departmentName,
            'organization' => $organizationName,
        ];

        if ($canSeePersonnelData) {
            $profile['phone'] = $user->getPhone();
            $profile['email'] = $user->getEmail();
            $profile['last_seen_at'] = $user->getLastSeenAt()?->format('c');
        }

        return $this->json($profile);
    }
}
