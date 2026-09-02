<?php

declare(strict_types=1);

namespace App\Repository\Acknowledgment;

use App\Entity\Acknowledgment\AckCategory;
use App\Entity\Acknowledgment\AckDocument;
use App\Entity\User\User;
use App\Enum\Acknowledgment\AckAudience;
use App\Enum\Acknowledgment\AckStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Удалённые документы отсекает фильтр softdeleteable (включён глобально),
 * поэтому условия на deletedAt здесь нигде нет.
 *
 * @extends ServiceEntityRepository<AckDocument>
 */
class AckDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AckDocument::class);
    }

    /**
     * Документы, ждущие отметки этого сотрудника.
     *
     * Две выборки вместо одной, потому что случаи разные по своей природе:
     * адресный документ ищется от строки назначения, документ «для всех» — от
     * самого документа, строки у которого может не быть вовсе. UNION в DQL нет,
     * а городить ради этого нативный SQL незачем: у одного человека таких
     * документов единицы.
     *
     * @return AckDocument[]
     */
    public function findPendingFor(User $user): array
    {
        $documents = array_merge(
            $this->pendingSelectedQb($user)->getQuery()->getResult(),
            $this->pendingAllQb($user)->getQuery()->getResult(),
        );

        // Сначала с дедлайном (ближайший выше), потом остальные — свежие выше.
        usort($documents, static function (AckDocument $a, AckDocument $b): int {
            $deadlineA = $a->getDeadline();
            $deadlineB = $b->getDeadline();

            if ($deadlineA !== null && $deadlineB !== null) {
                return $deadlineA <=> $deadlineB;
            }

            if ($deadlineA !== null || $deadlineB !== null) {
                return $deadlineA !== null ? -1 : 1;
            }

            return $b->getPublishedAt() <=> $a->getPublishedAt();
        });

        return $documents;
    }

    /** Число неотмеченных документов — для бейджа, отдельным лёгким запросом. */
    public function countPendingFor(User $user): int
    {
        $selected = (int) $this->pendingSelectedQb($user)
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $all = (int) $this->pendingAllQb($user)
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return $selected + $all;
    }

    /**
     * Реестр делопроизводства. Черновики и архив видны здесь и только здесь.
     *
     * @return AckDocument[]
     */
    public function findForRegistry(?AckCategory $category, bool $includeArchived, int $page, int $limit): array
    {
        return $this->registryQb($category, $includeArchived)
            ->orderBy('d.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countForRegistry(?AckCategory $category, bool $includeArchived): int
    {
        return (int) $this->registryQb($category, $includeArchived)
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Виден ли документ сотруднику: адресный — только назначенным. */
    public function isAddressedTo(AckDocument $document, User $user): bool
    {
        if ($document->getAudience() === AckAudience::ALL) {
            return true;
        }

        $count = (int) $this->getEntityManager()
            ->createQuery(
                'SELECT COUNT(adu.id) FROM App\Entity\Acknowledgment\AckDocumentUser adu
                 WHERE adu.document = :document AND adu.user = :user'
            )
            ->setParameter('document', $document)
            ->setParameter('user', $user)
            ->getSingleScalarResult();

        return $count > 0;
    }

    private function registryQb(?AckCategory $category, bool $includeArchived): QueryBuilder
    {
        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.category', 'c')
            ->addSelect('c');

        if ($category !== null) {
            $qb->andWhere('d.category = :category')->setParameter('category', $category);
        }

        if (!$includeArchived) {
            $qb->andWhere('d.archivedAt IS NULL');
        }

        return $qb;
    }

    /** Назначен адресно и ещё не закрыл: строка есть, статус пуст или «позже». */
    private function pendingSelectedQb(User $user): QueryBuilder
    {
        return $this->activeQb()
            ->innerJoin(
                'App\Entity\Acknowledgment\AckDocumentUser',
                'adu',
                Join::WITH,
                'adu.document = d AND adu.user = :user'
            )
            ->andWhere('d.audience = :selected')
            ->andWhere('adu.status IS NULL OR adu.status = :later')
            ->setParameter('user', $user)
            ->setParameter('selected', AckAudience::SELECTED)
            ->setParameter('later', AckStatus::LATER);
    }

    /** Документ «для всех», по которому у меня нет закрывающей строки. */
    private function pendingAllQb(User $user): QueryBuilder
    {
        return $this->activeQb()
            ->leftJoin(
                'App\Entity\Acknowledgment\AckDocumentUser',
                'adu',
                Join::WITH,
                'adu.document = d AND adu.user = :user'
            )
            ->andWhere('d.audience = :all')
            ->andWhere('adu.id IS NULL OR adu.status = :later')
            ->setParameter('user', $user)
            ->setParameter('all', AckAudience::ALL)
            ->setParameter('later', AckStatus::LATER);
    }

    private function activeQb(): QueryBuilder
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.category', 'c')
            ->addSelect('c')
            ->where('d.publishedAt IS NOT NULL')
            ->andWhere('d.archivedAt IS NULL');
    }
}
