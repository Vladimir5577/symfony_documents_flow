<?php

namespace App\Repository\Chat;

use App\Entity\Chat\ChatRoom;
use App\Entity\User\User;
use App\Enum\Chat\ChatRoomType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatRoom>
 */
class ChatRoomRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatRoom::class);
    }

    public function findUserRooms(User $user): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT
                r.id,
                r.type,
                r.name,
                r.created_by_id,
                r.created_at,
                r.organization_id,
                lm.id AS last_message_id,
                lm.content AS last_message_content,
                lm.created_at AS last_message_at,
                lm.sender_id AS last_message_sender_id,
                lm.deleted_at AS last_message_deleted_at,
                ls.lastname AS last_message_sender_lastname,
                ls.firstname AS last_message_sender_firstname,
                COALESCE(unread.cnt, 0) AS unread_count
            FROM chat_room r
            INNER JOIN chat_participant cp ON cp.room_id = r.id AND cp.user_id = :userId
            LEFT JOIN LATERAL (
                SELECT m.id, m.content, m.created_at, m.sender_id, m.deleted_at
                FROM chat_message m
                WHERE m.room_id = r.id
                ORDER BY m.id DESC
                LIMIT 1
            ) lm ON true
            LEFT JOIN "user" ls ON ls.id = lm.sender_id
            LEFT JOIN LATERAL (
                SELECT COUNT(*) AS cnt
                FROM chat_message m2
                WHERE m2.room_id = r.id
                  AND m2.sender_id != :userId
                  AND m2.deleted_at IS NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM chat_message_read rd
                      WHERE rd.message_id = m2.id AND rd.user_id = :userId
                  )
            ) unread ON true
            ORDER BY lm.created_at DESC NULLS LAST
        ';

        return $conn->fetchAllAssociative($sql, ['userId' => $user->getId()]);
    }

    public function findPrivateRoomBetween(User $user1, User $user2): ?ChatRoom
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.type = :type')
            ->setParameter('type', ChatRoomType::PRIVATE)
            ->andWhere('EXISTS (
                SELECT 1 FROM App\Entity\Chat\ChatParticipant p1
                WHERE p1.room = r AND p1.user = :user1
            )')
            ->andWhere('EXISTS (
                SELECT 1 FROM App\Entity\Chat\ChatParticipant p2
                WHERE p2.room = r AND p2.user = :user2
            )')
            ->setParameter('user1', $user1)
            ->setParameter('user2', $user2)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
