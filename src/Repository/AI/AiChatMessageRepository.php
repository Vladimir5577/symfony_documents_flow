<?php

declare(strict_types=1);

namespace App\Repository\AI;

use App\Entity\AI\AiChatMessage;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiChatMessage>
 */
class AiChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiChatMessage::class);
    }

    /**
     * Последние N сообщений юзера в хронологическом порядке (сначала старые).
     * Используется и для отображения истории на странице, и для сборки контекста при вызове API.
     * Не включает failed-сообщения, они в контекст ИИ не идут (но в UI могут показаться отдельно при желании).
     *
     * @return AiChatMessage[]
     */
    public function findRecentForUser(User $user, int $limit, bool $onlyCompleted = false): array
    {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('m.id', 'DESC')
            ->setMaxResults($limit);

        if ($onlyCompleted) {
            $qb->andWhere('m.status = :completed')
                ->setParameter('completed', AiChatMessage::STATUS_COMPLETED);
        }

        // Тянем в обратном порядке (последние N), затем разворачиваем для естественной хронологии.
        $messages = $qb->getQuery()->getResult();

        return array_reverse($messages);
    }

    public function deleteAllForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('m')
            ->delete()
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
