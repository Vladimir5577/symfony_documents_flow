<?php

declare(strict_types=1);

namespace App\Repository\Acknowledgment;

use App\Entity\Acknowledgment\AckDocument;
use App\Entity\Acknowledgment\AckDocumentUser;
use App\Entity\User\User;
use App\Enum\Acknowledgment\AckAudience;
use App\Enum\Acknowledgment\AckStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AckDocumentUser>
 */
class AckDocumentUserRepository extends ServiceEntityRepository
{
    /** Отчёт «не ознакомились»: строки нет, либо она есть, но без отметки. */
    public const FILTER_PENDING = 'pending';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AckDocumentUser::class);
    }

    public function findOneFor(AckDocument $document, User $user): ?AckDocumentUser
    {
        return $this->findOneBy(['document' => $document, 'user' => $user]);
    }

    /**
     * Мои закрытые документы: «ознакомлен» и «ознакомлен, не согласен».
     * Архивные тоже показываем — человек их читал, из истории они не исчезают.
     *
     * @return AckDocumentUser[]
     */
    public function findHistoryFor(User $user, int $page, int $limit): array
    {
        return $this->historyQb($user)
            ->orderBy('adu.actedAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countHistoryFor(User $user): int
    {
        return (int) $this->historyQb($user)
            ->select('COUNT(adu.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Счётчики по документам одним запросом — реестру они нужны на каждую
     * строку, а по запросу на документ это был бы классический N+1.
     *
     * @param int[] $documentIds
     *
     * @return array<int, array{acknowledged: int, disagreed: int, later: int, rows: int}>
     */
    public function statusCountsFor(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        $rows = $this->statusCountsQb($documentIds)->getQuery()->getResult();

        $counts = [];
        foreach ($documentIds as $documentId) {
            $counts[$documentId] = ['acknowledged' => 0, 'disagreed' => 0, 'later' => 0, 'rows' => 0];
        }

        foreach ($rows as $row) {
            $documentId = (int) $row['documentId'];
            $count = (int) $row['cnt'];
            $counts[$documentId]['rows'] += $count;

            $key = match ($row['status']) {
                AckStatus::ACKNOWLEDGED, AckStatus::ACKNOWLEDGED->value => 'acknowledged',
                AckStatus::DISAGREED, AckStatus::DISAGREED->value => 'disagreed',
                AckStatus::LATER, AckStatus::LATER->value => 'later',
                default => null,
            };

            if ($key !== null) {
                $counts[$documentId][$key] += $count;
            }
        }

        return $counts;
    }

    /**
     * Строки отчёта по документу: человек и его отметка, если она есть.
     *
     * Идём от пользователей, а не от отметок: для аудитории «все» строки у
     * большинства просто нет, и выборка из ack_document_user показала бы только
     * тех, кто уже отреагировал, — то есть ровно не тех, кого ищут в отчёте.
     *
     * Отдаём скаляры, а не сущности: у User обратная сторона OneToOne на Worker,
     * и каждая гидрация человека стоит отдельного SELECT. На странице отчёта в
     * полсотни строк это полсотни лишних запросов на ровном месте.
     *
     * @return list<array{userId: int, lastname: string, firstname: string, patronymic: ?string,
     *                    status: ?AckStatus, comment: ?string, actedAt: ?\DateTimeImmutable}>
     */
    public function findReportRows(AckDocument $document, ?string $filter, int $page, int $limit): array
    {
        return $this->reportQb($document, $filter)
            ->select(
                'u.id AS userId',
                'u.lastname AS lastname',
                'u.firstname AS firstname',
                'u.patronymic AS patronymic',
                'adu.status AS status',
                'adu.comment AS comment',
                'adu.actedAt AS actedAt',
            )
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countReportRows(AckDocument $document, ?string $filter): int
    {
        return (int) $this->reportQb($document, $filter)
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Знаменатель отчёта: для «всех» — текущий состав, для адресного — назначенные. */
    public function countAudience(AckDocument $document): int
    {
        if ($document->getAudience() === AckAudience::SELECTED) {
            return (int) $this->createQueryBuilder('adu')
                ->select('COUNT(adu.id)')
                ->where('adu.document = :document')
                ->setParameter('document', $document)
                ->getQuery()
                ->getSingleScalarResult();
        }

        return (int) $this->getEntityManager()
            ->createQuery('SELECT COUNT(u.id) FROM App\Entity\User\User u')
            ->getSingleScalarResult();
    }

    /**
     * @param int[] $documentIds
     */
    private function statusCountsQb(array $documentIds): QueryBuilder
    {
        return $this->createQueryBuilder('adu')
            ->select('IDENTITY(adu.document) AS documentId', 'adu.status AS status', 'COUNT(adu.id) AS cnt')
            ->where('adu.document IN (:ids)')
            ->setParameter('ids', $documentIds)
            ->groupBy('documentId', 'adu.status');
    }

    private function historyQb(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('adu')
            ->innerJoin('adu.document', 'd')
            ->addSelect('d')
            ->leftJoin('d.category', 'c')
            ->addSelect('c')
            ->where('adu.user = :user')
            ->andWhere('adu.status IN (:final)')
            ->andWhere('d.publishedAt IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('final', [AckStatus::ACKNOWLEDGED, AckStatus::DISAGREED]);
    }

    private function reportQb(AckDocument $document, ?string $filter): QueryBuilder
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->from(User::class, 'u')
            ->setParameter('document', $document);

        // Для адресного документа аудитория — это сами строки назначения,
        // для «всех» — весь текущий состав, поэтому join разный.
        $joinType = $document->getAudience() === AckAudience::SELECTED ? 'innerJoin' : 'leftJoin';
        $qb->{$joinType}(
            AckDocumentUser::class,
            'adu',
            Join::WITH,
            'adu.user = u AND adu.document = :document'
        );

        if ($filter === self::FILTER_PENDING) {
            $qb->andWhere('adu.id IS NULL OR adu.status IS NULL');
        } elseif ($filter !== null) {
            $status = AckStatus::tryFrom($filter);
            if ($status !== null) {
                $qb->andWhere('adu.status = :status')->setParameter('status', $status);
            }
        }

        return $qb;
    }
}
