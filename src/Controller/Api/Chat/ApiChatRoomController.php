<?php

namespace App\Controller\Api\Chat;

use App\Entity\User\User;
use App\Enum\Chat\ChatRoomType;
use App\Repository\Chat\ChatParticipantRepository;
use App\Repository\Chat\ChatRoomRepository;
use App\Repository\User\UserRepository;
use App\Service\Chat\ChatRoomService;
use Doctrine\ORM\EntityManagerInterface;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/chat/rooms')]
final class ApiChatRoomController extends AbstractController
{
    public function __construct(
        private readonly ChatRoomService $roomService,
        private readonly ChatRoomRepository $roomRepo,
        private readonly ChatParticipantRepository $participantRepo,
        private readonly UserRepository $userRepo,
        private readonly EntityManagerInterface $em,
        private readonly CacheManager $imagineCacheManager,
    ) {
    }

    #[Route('', name: 'api_chat_rooms_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $rooms = $this->roomService->getUserRooms($user);

        $result = [];
        foreach ($rooms as $room) {
            $displayName = $room['name'];
            $otherUserId = null;
            $otherUserAvatar = null;

            if ($room['type'] === ChatRoomType::PRIVATE->value) {
                $participants = $this->participantRepo->findByRoom(
                    $this->roomRepo->find($room['id'])
                );
                foreach ($participants as $p) {
                    if ($p->getUser()->getId() !== $user->getId()) {
                        $other = $p->getUser();
                        $otherUserId = $other->getId();
                        $otherUserAvatar = $other->getAvatarName()
                            ? $this->imagineCacheManager->getBrowserPath(
                                $other->getId() . '/' . $other->getAvatarName(),
                                'avatar_medium'
                            )
                            : null;
                        if (!$displayName) {
                            $displayName = trim($other->getLastname() . ' ' . $other->getFirstname());
                        }
                        break;
                    }
                }
            }

            $lastPreview = $room['last_message_content'];
            if ($room['last_message_deleted_at'] !== null) {
                $lastPreview = 'Сообщение удалено';
            }

            $entry = [
                'id' => $room['id'],
                'type' => $room['type'],
                'name' => $displayName,
                'last_message' => $lastPreview,
                'last_message_at' => $room['last_message_at'],
                'last_message_sender' => $room['last_message_sender_lastname']
                    ? trim($room['last_message_sender_lastname'] . ' ' . ($room['last_message_sender_firstname'] ?? ''))
                    : null,
                'unread_count' => (int) $room['unread_count'],
            ];

            if ($otherUserId !== null) {
                $entry['other_user_id'] = $otherUserId;
                $entry['other_user_avatar'] = $otherUserAvatar;
            }

            $result[] = $entry;
        }

        return $this->json($result);
    }

    #[Route('/private', name: 'api_chat_rooms_private', methods: ['POST'])]
    public function createPrivate(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);

        $targetUserId = $data['user_id'] ?? null;
        if (!$targetUserId) {
            return $this->json(['error' => 'user_id is required'], 400);
        }

        $targetUser = $this->userRepo->find($targetUserId);
        if (!$targetUser) {
            return $this->json(['error' => 'User not found'], 404);
        }

        if ($targetUser->getId() === $user->getId()) {
            return $this->json(['error' => 'Cannot create chat with yourself'], 400);
        }

        $room = $this->roomService->createPrivateRoom($user, $targetUser);

        return $this->json([
            'id' => $room->getId(),
            'type' => $room->getType()->value,
            'name' => trim($targetUser->getLastname() . ' ' . $targetUser->getFirstname()),
        ], 201);
    }

    #[Route('/group', name: 'api_chat_rooms_group', methods: ['POST'])]
    public function createGroup(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MANAGER');

        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);

        $name = $data['name'] ?? null;
        $userIds = $data['user_ids'] ?? [];

        if (!$name || empty($userIds)) {
            return $this->json(['error' => 'name and user_ids are required'], 400);
        }

        $participants = $this->userRepo->findBy(['id' => $userIds]);
        if (empty($participants)) {
            return $this->json(['error' => 'No valid users found'], 400);
        }

        $room = $this->roomService->createGroupRoom($user, $name, $participants);

        return $this->json([
            'id' => $room->getId(),
            'type' => $room->getType()->value,
            'name' => $room->getName(),
        ], 201);
    }

    #[Route('/department', name: 'api_chat_rooms_department', methods: ['POST'])]
    public function createDepartment(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MANAGER');

        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);

        $departmentId = $data['department_id'] ?? null;
        if (!$departmentId) {
            return $this->json(['error' => 'department_id is required'], 400);
        }

        $department = $this->em->getRepository(\App\Entity\Organization\Department::class)->find($departmentId);
        if (!$department) {
            return $this->json(['error' => 'Department not found'], 404);
        }

        $room = $this->roomService->createDepartmentRoom($user, $department);

        return $this->json([
            'id' => $room->getId(),
            'type' => $room->getType()->value,
            'name' => $room->getName(),
        ], 201);
    }

    #[Route('/{id}', name: 'api_chat_rooms_detail', methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $room = $this->roomRepo->find($id);
        if (!$room) {
            return $this->json(['error' => 'Room not found'], 404);
        }

        if (!$this->participantRepo->isParticipant($room, $user)) {
            return $this->json(['error' => 'Access denied'], 403);
        }

        $participants = $this->participantRepo->findByRoom($room);
        $participantsList = [];
        foreach ($participants as $p) {
            $u = $p->getUser();
            $participantsList[] = [
                'id' => $u->getId(),
                'lastname' => $u->getLastname(),
                'firstname' => $u->getFirstname(),
                'avatar' => $u->getAvatarName()
                    ? $this->imagineCacheManager->getBrowserPath($u->getId() . '/' . $u->getAvatarName(), 'avatar_medium')
                    : null,
            ];
        }

        $createdBy = $room->getCreatedBy();

        return $this->json([
            'id' => $room->getId(),
            'type' => $room->getType()->value,
            'name' => $room->getName(),
            'created_by' => $createdBy ? [
                'id' => $createdBy->getId(),
                'lastname' => $createdBy->getLastname(),
                'firstname' => $createdBy->getFirstname(),
            ] : null,
            'participants' => $participantsList,
        ]);
    }

    #[Route('/{id}/participants', name: 'api_chat_rooms_add_participant', methods: ['POST'])]
    public function addParticipant(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $room = $this->roomRepo->find($id);
        if (!$room) {
            return $this->json(['error' => 'Room not found'], 404);
        }

        if ($room->getCreatedBy()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'Only room creator can add participants'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $targetUser = $this->userRepo->find($data['user_id'] ?? 0);
        if (!$targetUser) {
            return $this->json(['error' => 'User not found'], 404);
        }

        $this->roomService->addParticipant($room, $targetUser);

        return $this->json(['success' => true]);
    }

    #[Route('/{id}/participants/{userId}', name: 'api_chat_rooms_remove_participant', methods: ['DELETE'])]
    public function removeParticipant(int $id, int $userId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $room = $this->roomRepo->find($id);
        if (!$room) {
            return $this->json(['error' => 'Room not found'], 404);
        }

        if ($room->getCreatedBy()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'Only room creator can remove participants'], 403);
        }

        $targetUser = $this->userRepo->find($userId);
        if (!$targetUser) {
            return $this->json(['error' => 'User not found'], 404);
        }

        $this->roomService->removeParticipant($room, $targetUser);

        return $this->json(['success' => true]);
    }
}
