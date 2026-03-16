<?php

namespace App\Repository\Chat;

use App\Entity\Chat\ChatParticipant;
use App\Entity\Chat\ChatRoom;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatParticipant>
 */
class ChatParticipantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatParticipant::class);
    }

    public function isParticipant(ChatRoom $room, User $user): bool
    {
        return (bool) $this->createQueryBuilder('cp')
            ->select('1')
            ->andWhere('cp.room = :room')
            ->andWhere('cp.user = :user')
            ->setParameter('room', $room)
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return ChatParticipant[]
     */
    public function findByRoom(ChatRoom $room): array
    {
        return $this->createQueryBuilder('cp')
            ->andWhere('cp.room = :room')
            ->setParameter('room', $room)
            ->join('cp.user', 'u')
            ->addSelect('u')
            ->getQuery()
            ->getResult();
    }

    public function countByRoom(ChatRoom $room): int
    {
        return (int) $this->createQueryBuilder('cp')
            ->select('COUNT(cp.id)')
            ->andWhere('cp.room = :room')
            ->setParameter('room', $room)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
