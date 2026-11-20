<?php

declare(strict_types=1);

namespace App\Repository\Document;

use App\Entity\Document\DocumentBoard;
use App\Entity\Document\DocumentUserRecipient;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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

    /**
     * Список без колонки scene. Участник проверяется через EXISTS, чтобы join не размножал строки.
     *
     * @return list<DocumentBoard>
     */
    public function findVisibleTo(User $user, bool $isAdmin, ?int $documentId): array
    {
        $qb = $this->summaryQuery();

        if ($documentId !== null) {
            $qb->andWhere('d.id = :documentId')->setParameter('documentId', $documentId);
        }

        if (!$isAdmin) {
            $qb->andWhere(
                'b.createdBy = :user OR d.createdBy = :user OR EXISTS (
                    SELECT p.id FROM App\Entity\Document\DocumentBoard memberBoard
                    JOIN memberBoard.participants p
                    WHERE memberBoard = b AND p = :user
                ) OR EXISTS (
                    SELECT r.id FROM App\Entity\Document\DocumentUserRecipient r
                    WHERE r.document = d AND r.user = :user
                )',
            )->setParameter('user', $user);
        }

        /** @var list<DocumentBoard> $boards */
        $boards = $qb->getQuery()->getResult();
        $this->keepReadOnly($boards);

        return $boards;
    }

    /** Карточка для ответа без scene: название, документ, автор. Участники — отдельно, коллекция partial их не держит. */
    public function findSummary(int $id): ?DocumentBoard
    {
        $board = $this->summaryQuery()
            ->andWhere('b.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
        if (!$board instanceof DocumentBoard) {
            return null;
        }
        $this->keepReadOnly([$board]);

        return $board;
    }

    /** @return list<User> */
    public function findParticipantUsers(int $boardId): array
    {
        /** @var list<User> $users */
        $users = $this->getEntityManager()->createQuery(
            'SELECT u FROM App\Entity\User\User u
             WHERE u.id IN (
                SELECT participant.id FROM App\Entity\Document\DocumentBoard b
                JOIN b.participants participant
                WHERE b.id = :id
             )',
        )->setParameter('id', $boardId)->getResult();

        return $users;
    }

    public function touch(int $id, ?string $title = null): void
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->update(DocumentBoard::class, 'b')
            ->set('b.updatedAt', ':now')
            ->where('b.id = :id')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('id', $id);
        if ($title !== null) {
            $qb->set('b.title', ':title')->setParameter('title', $title);
        }
        $qb->getQuery()->execute();
    }

    /** @param list<int> $userIds */
    public function setParticipantIds(int $boardId, array $userIds): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement('DELETE FROM document_board_user WHERE board_id = :board', ['board' => $boardId]);
        foreach ($userIds as $userId) {
            $connection->executeStatement(
                'INSERT INTO document_board_user (board_id, user_id) VALUES (:board, :user)',
                ['board' => $boardId, 'user' => $userId],
            );
        }
    }

    /** partial: scene не выбирается. Read-only, чтобы случайный flush не записал пустую scene. */
    private function summaryQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('b')
            ->select('partial b.{id, title, createdAt, updatedAt}')
            ->addSelect('d')
            ->addSelect('author')
            ->addSelect('docAuthor')
            ->leftJoin('b.document', 'd')
            ->leftJoin('b.createdBy', 'author')
            ->leftJoin('d.createdBy', 'docAuthor')
            ->orderBy('b.updatedAt', 'DESC');
    }

    /** @param list<DocumentBoard> $boards */
    private function keepReadOnly(array $boards): void
    {
        $unitOfWork = $this->getEntityManager()->getUnitOfWork();
        foreach ($boards as $board) {
            $unitOfWork->markReadOnly($board);
        }
    }

    /**
     * Поля для проверки права. Колонку scene не читаем: ответ дергается на каждый штрих.
     *
     * @return array{id: int, createdById: int|null, documentId: int|null, documentAuthorId: int|null}|null
     */
    public function findAccessRow(int $id): ?array
    {
        /** @var array{id: int|string, createdById: int|string|null, documentId: int|string|null, documentAuthorId: int|string|null}|null $row */
        $row = $this->createQueryBuilder('b')
            ->select('b.id AS id')
            ->addSelect('IDENTITY(b.createdBy) AS createdById')
            ->addSelect('d.id AS documentId')
            ->addSelect('IDENTITY(d.createdBy) AS documentAuthorId')
            ->leftJoin('b.document', 'd')
            ->andWhere('b.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'createdById' => $row['createdById'] !== null ? (int) $row['createdById'] : null,
            'documentId' => $row['documentId'] !== null ? (int) $row['documentId'] : null,
            'documentAuthorId' => $row['documentAuthorId'] !== null ? (int) $row['documentAuthorId'] : null,
        ];
    }

    public function hasParticipant(int $boardId, int $userId): bool
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(p.id)')
            ->join('b.participants', 'p')
            ->andWhere('b.id = :board')
            ->andWhere('p.id = :user')
            ->setParameter('board', $boardId)
            ->setParameter('user', $userId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function isDocumentRecipient(int $documentId, int $userId): bool
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(DocumentUserRecipient::class, 'r')
            ->andWhere('IDENTITY(r.document) = :document')
            ->andWhere('IDENTITY(r.user) = :user')
            ->setParameter('document', $documentId)
            ->setParameter('user', $userId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
