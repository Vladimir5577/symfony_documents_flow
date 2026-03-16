<?php

namespace App\Repository\Chat;

use App\Entity\Chat\ChatMessage;
use App\Entity\Chat\ChatRoom;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatMessage>
 */
class ChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessage::class);
    }

    /**
     * @return ChatMessage[]
     */
    public function findByRoomPaginated(ChatRoom $room, ?int $beforeId, int $limit = 30): array
    {
        $em = $this->getEntityManager();
        $filters = $em->getFilters();
        $disabledSoftDelete = false;

        if ($filters->isEnabled('softdeleteable')) {
            $filters->disable('softdeleteable');
            $disabledSoftDelete = true;
        }

        try {
            $qb = $this->createQueryBuilder('m')
                ->andWhere('m.room = :room')
                ->setParameter('room', $room)
                ->leftJoin('m.sender', 's')
                ->addSelect('s')
                ->leftJoin('m.files', 'f')
                ->addSelect('f')
                ->orderBy('m.id', 'DESC')
                ->setMaxResults($limit);

            if ($beforeId !== null) {
                $qb->andWhere('m.id < :beforeId')
                    ->setParameter('beforeId', $beforeId);
            }

            return $qb->getQuery()->getResult();
        } finally {
            if ($disabledSoftDelete) {
                $filters->enable('softdeleteable');
            }
        }
    }

    public function countUnreadForUser(ChatRoom $room, User $user): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.room = :room')
            ->andWhere('m.sender != :user')
            ->setParameter('room', $room)
            ->setParameter('user', $user)
            ->andWhere('NOT EXISTS (
                SELECT 1 FROM App\Entity\Chat\ChatMessageRead r
                WHERE r.message = m AND r.user = :user
            )')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
