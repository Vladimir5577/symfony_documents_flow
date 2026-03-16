<?php

namespace App\Service\Chat;

use App\Entity\Chat\ChatFile;
use App\Entity\Chat\ChatMessage;
use App\Entity\Chat\ChatMessageRead;
use App\Entity\Chat\ChatRoom;
use App\Entity\User\User;
use App\Repository\Chat\ChatMessageReadRepository;
use App\Repository\Chat\ChatMessageRepository;
use App\Repository\Chat\ChatParticipantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

class ChatMessageService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ChatMessageRepository $messageRepository,
        private ChatMessageReadRepository $messageReadRepository,
        private ChatParticipantRepository $participantRepository,
        private HubInterface $hub,
        private CacheManager $imagineCacheManager,
    ) {
    }

    public function sendMessage(ChatRoom $room, User $sender, string $content, array $files = []): ChatMessage
    {
        $message = new ChatMessage();
        $message->setRoom($room);
        $message->setSender($sender);
        $message->setContent($content);
        $this->em->persist($message);

        foreach ($files as $uploadedFile) {
            if ($uploadedFile instanceof UploadedFile) {
                $chatFile = new ChatFile();
                $chatFile->setTitle($uploadedFile->getClientOriginalName());
                $chatFile->setFile($uploadedFile);
                $message->addFile($chatFile);
                $this->em->persist($chatFile);
            }
        }

        $this->em->flush();

        $read = new ChatMessageRead();
        $read->setMessage($message);
        $read->setUser($sender);
        $read->setReadAt(new \DateTimeImmutable());
        $this->em->persist($read);
        $this->em->flush();

        $messageData = $this->serializeMessage($message);

        $this->publishToMercure($room, [
            'type' => 'new_message',
            'message' => $messageData,
        ]);

        return $message;
    }

    public function editMessage(ChatMessage $message, User $user, string $newContent): ChatMessage
    {
        if ($message->getSender()?->getId() !== $user->getId()) {
            throw new \RuntimeException('Only the sender can edit a message');
        }

        if ($message->isDeleted()) {
            throw new \RuntimeException('Cannot edit a deleted message');
        }

        $message->setContent($newContent);
        $message->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->publishToMercure($message->getRoom(), [
            'type' => 'message_edited',
            'message' => $this->serializeMessage($message),
        ]);

        return $message;
    }

    public function deleteMessage(ChatMessage $message, User $user): void
    {
        if ($message->getSender()?->getId() !== $user->getId()) {
            throw new \RuntimeException('Only the sender can delete a message');
        }

        $message->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->publishToMercure($message->getRoom(), [
            'type' => 'message_deleted',
            'message_id' => $message->getId(),
            'room_id' => $message->getRoom()->getId(),
        ]);
    }

    public function markAsRead(ChatRoom $room, User $user): void
    {
        $this->messageReadRepository->markAllAsRead($room, $user);

        $this->publishToMercure($room, [
            'type' => 'read',
            'room_id' => $room->getId(),
            'user_id' => $user->getId(),
        ]);
    }

    public function getMessages(ChatRoom $room, ?int $beforeId, int $limit = 30): array
    {
        return $this->messageRepository->findByRoomPaginated($room, $beforeId, $limit);
    }

    public function getUnreadCount(User $user): int
    {
        $conn = $this->em->getConnection();

        $sql = '
            SELECT COUNT(*) FROM chat_message m
            INNER JOIN chat_participant cp ON cp.room_id = m.room_id AND cp.user_id = :userId
            WHERE m.sender_id != :userId
              AND m.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM chat_message_read r
                  WHERE r.message_id = m.id AND r.user_id = :userId
              )
        ';

        return (int) $conn->fetchOne($sql, ['userId' => $user->getId()]);
    }

    public function publishToMercure(ChatRoom $room, array $data): void
    {
        $json = json_encode($data);

        $this->hub->publish(new Update(
            '/chat/room/' . $room->getId(),
            $json
        ));

        $participants = $this->participantRepository->findByRoom($room);
        foreach ($participants as $participant) {
            $this->hub->publish(new Update(
                '/chat/user/' . $participant->getUser()->getId(),
                json_encode([
                    'type' => 'room_updated',
                    'room_id' => $room->getId(),
                ])
            ));
        }
    }

    public function serializeMessage(ChatMessage $message): array
    {
        $sender = $message->getSender();
        $files = [];
        foreach ($message->getFiles() as $file) {
            $files[] = [
                'id' => $file->getId(),
                'title' => $file->getTitle(),
                'path' => $file->getFilePath() ? '/uploads/chats/' . $message->getRoom()->getId() . '/' . $file->getFilePath() : null,
            ];
        }

        $readCount = $this->messageReadRepository->countReaders($message);
        $participantCount = $this->participantRepository->countByRoom($message->getRoom());

        return [
            'id' => $message->getId(),
            'room_id' => $message->getRoom()->getId(),
            'sender' => $sender ? [
                'id' => $sender->getId(),
                'lastname' => $sender->getLastname(),
                'firstname' => $sender->getFirstname(),
                'avatar' => $sender->getAvatarName()
                    ? $this->imagineCacheManager->getBrowserPath($sender->getId() . '/' . $sender->getAvatarName(), 'avatar_medium')
                    : null,
            ] : null,
            'content' => $message->isDeleted() ? null : $message->getContent(),
            'is_deleted' => $message->isDeleted(),
            'is_read' => $readCount >= $participantCount,
            'files' => $message->isDeleted() ? [] : $files,
            'created_at' => $message->getCreatedAt()?->format('c'),
            'updated_at' => $message->getUpdatedAt()?->format('c'),
        ];
    }
}
