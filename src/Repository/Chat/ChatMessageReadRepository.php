<?php

namespace App\Repository\Chat;

use App\Entity\Chat\ChatMessage;
use App\Entity\Chat\ChatMessageRead;
use App\Entity\Chat\ChatRoom;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatMessageRead>
 */
class ChatMessageReadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessageRead::class);
    }

    public function markAllAsRead(ChatRoom $room, User $user): void
    {
        $conn = $this->getEntityManager()->getConnection();

        $conn->executeStatement(
            'INSERT INTO chat_message_read (message_id, user_id, read_at)
             SELECT m.id, :userId, NOW()
             FROM chat_message m
             WHERE m.room_id = :roomId
               AND m.deleted_at IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM chat_message_read r
                   WHERE r.message_id = m.id AND r.user_id = :userId
               )
             ON CONFLICT (message_id, user_id) DO NOTHING',
            [
                'roomId' => $room->getId(),
                'userId' => $user->getId(),
            ]
        );
    }

    public function countReaders(ChatMessage $message): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.message = :message')
            ->setParameter('message', $message)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
