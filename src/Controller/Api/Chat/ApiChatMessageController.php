<?php

namespace App\Controller\Api\Chat;

use App\Entity\User\User;
use App\Repository\Chat\ChatMessageRepository;
use App\Repository\Chat\ChatParticipantRepository;
use App\Repository\Chat\ChatRoomRepository;
use App\Service\Chat\ChatMessageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/chat')]
final class ApiChatMessageController extends AbstractController
{
    public function __construct(
        private readonly ChatMessageService $messageService,
        private readonly ChatRoomRepository $roomRepo,
        private readonly ChatMessageRepository $messageRepo,
        private readonly ChatParticipantRepository $participantRepo,
    ) {
    }

    #[Route('/rooms/{id}/messages', name: 'api_chat_messages_list', methods: ['GET'])]
    public function messages(int $id, Request $request): JsonResponse
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

        $beforeId = $request->query->getInt('before') ?: null;
        $messages = $this->messageService->getMessages($room, $beforeId);

        $result = [];
        foreach ($messages as $message) {
            $result[] = $this->messageService->serializeMessage($message);
        }

        return $this->json($result);
    }

    #[Route('/rooms/{id}/messages', name: 'api_chat_messages_send', methods: ['POST'])]
    public function send(int $id, Request $request): JsonResponse
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

        $contentType = $request->headers->get('Content-Type', '');

        if (str_contains($contentType, 'multipart/form-data')) {
            $content = $request->request->get('content', '');
            $files = $request->files->all('files') ?: [];
        } else {
            $data = json_decode($request->getContent(), true);
            $content = $data['content'] ?? '';
            $files = [];
        }

        if (empty(trim($content)) && empty($files)) {
            return $this->json(['error' => 'Message content or files required'], 400);
        }

        $message = $this->messageService->sendMessage($room, $user, $content, $files);

        return $this->json($this->messageService->serializeMessage($message), 201);
    }

    #[Route('/messages/{id}', name: 'api_chat_messages_edit', methods: ['PUT'])]
    public function edit(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $message = $this->messageRepo->find($id);
        if (!$message) {
            return $this->json(['error' => 'Message not found'], 404);
        }

        if ($message->getSender()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'Only the sender can edit a message'], 403);
        }

        if ($message->isDeleted()) {
            return $this->json(['error' => 'Cannot edit a deleted message'], 400);
        }

        $data = json_decode($request->getContent(), true);
        $content = trim($data['content'] ?? '');

        if (empty($content)) {
            return $this->json(['error' => 'Content is required'], 400);
        }

        $this->messageService->editMessage($message, $user, $content);

        return $this->json($this->messageService->serializeMessage($message));
    }

    #[Route('/messages/{id}', name: 'api_chat_messages_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $em = $this->messageRepo->getEntityManager();
        $filters = $em->getFilters();
        $wasEnabled = $filters->isEnabled('softdeleteable');
        if ($wasEnabled) {
            $filters->disable('softdeleteable');
        }

        $message = $this->messageRepo->find($id);

        if ($wasEnabled) {
            $filters->enable('softdeleteable');
        }

        if (!$message) {
            return $this->json(['error' => 'Message not found'], 404);
        }

        if ($message->getSender()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'Only the sender can delete a message'], 403);
        }

        $this->messageService->deleteMessage($message, $user);

        return $this->json(['success' => true]);
    }

    #[Route('/rooms/{id}/read', name: 'api_chat_messages_read', methods: ['POST'])]
    public function markAsRead(int $id): JsonResponse
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

        $this->messageService->markAsRead($room, $user);

        return $this->json(['success' => true]);
    }

    #[Route('/unread-count', name: 'api_chat_unread_count', methods: ['GET'])]
    public function unreadCount(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $count = $this->messageService->getUnreadCount($user);

        return $this->json(['count' => $count]);
    }
}
