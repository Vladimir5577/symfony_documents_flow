<?php

declare(strict_types=1);

namespace App\Repository\Document;

use App\Entity\Document\DocumentBoard;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentBoard>
 */
class DocumentBoardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentBoard::class);
    }

    /** @return list<DocumentBoard> */
    public function findVisibleTo(User $user, bool $isAdmin, ?int $documentId): array
    {
        $qb = $this->createQueryBuilder('b')
            ->leftJoin('b.document', 'd')
            ->leftJoin('b.participants', 'p')
            ->distinct()
            ->orderBy('b.updatedAt', 'DESC');

        if ($documentId !== null) {
            $qb->andWhere('d.id = :documentId')->setParameter('documentId', $documentId);
        }

        if (!$isAdmin) {
            $qb->andWhere(
                'b.createdBy = :user OR p = :user OR d.createdBy = :user OR EXISTS (
                    SELECT r.id FROM App\Entity\Document\DocumentUserRecipient r
                    WHERE r.document = d AND r.user = :user
                )',
            )->setParameter('user', $user);
        }

        /** @var list<DocumentBoard> $boards */
        $boards = $qb->getQuery()->getResult();

        return $boards;
    }
}
