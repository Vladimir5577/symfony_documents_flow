<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\Upd;
use App\Service\Inventory\InventoryScope;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Upd>
 */
class UpdRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Upd::class);
    }

    /**
     * @param array{
     *     organizationIds?: int[]|null,
     *     dateFrom?: \DateTimeImmutable|null,
     *     dateTo?: \DateTimeImmutable|null,
     *     search?: string
     * } $filters
     *
     * @return array{items: Upd[], total: int, page: int, limit: int, totalPages: int}
     */
    public function findPaginated(InventoryScope $scope, array $filters, int $page, int $limit): array
    {
        // Автор (`cb`) идёт в карточку, его работник (`cbw`) — нет, но джойн обязателен:
        // worker — инверсная сторона OneToOne у User, прокси на неё построить нельзя.
        // Без обоих джойнов страница из 20 документов стоит +40 запросов.
        $qb = $this->createQueryBuilder('u')
            ->addSelect('o', 'cb', 'cbw')
            ->join('u.organization', 'o')
            ->leftJoin('u.createdBy', 'cb')
            ->leftJoin('cb.worker', 'cbw');

        $this->applyScope($qb, $scope);
        $this->applyFilters($qb, $filters);

        $total = (int) (clone $qb)
            ->resetDQLPart('orderBy')
            ->select('COUNT(DISTINCT u.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Свежие документы сверху. Добивка по id: дата — это DATE, у одной поставки
        // легко несколько документов в один день, и без тай-брейкера они разъезжались
        // бы между страницами.
        $items = $qb
            ->orderBy('u.date', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $total > 0 ? (int) ceil($total / $limit) : 1,
        ];
    }

    /**
     * Ограничение видимости. Проще, чем у товаров: у документа нет категории,
     * поэтому ветка «ответственный за категорию» отсутствует вовсе — такой человек
     * документов не видит, ему остаются номер и дата на карточке его позиции.
     */
    private function applyScope(QueryBuilder $qb, InventoryScope $scope): void
    {
        if ($scope->full) {
            return;
        }

        if ($scope->adminOrgIds === []) {
            $qb->andWhere('1 = 0');

            return;
        }

        $qb->andWhere('u.organization IN (:scopeAdminOrgs)')
            ->setParameter('scopeAdminOrgs', $scope->adminOrgIds);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['organizationIds'])) {
            $qb->andWhere('u.organization IN (:organizationIds)')
                ->setParameter('organizationIds', $filters['organizationIds']);
        }

        if (($filters['dateFrom'] ?? null) instanceof \DateTimeImmutable) {
            $qb->andWhere('u.date >= :dateFrom')->setParameter('dateFrom', $filters['dateFrom']);
        }

        if (($filters['dateTo'] ?? null) instanceof \DateTimeImmutable) {
            $qb->andWhere('u.date <= :dateTo')->setParameter('dateTo', $filters['dateTo']);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $qb->andWhere('LOWER(u.number) LIKE LOWER(:search) OR LOWER(u.supplier) LIKE LOWER(:search)')
                ->setParameter('search', '%' . $search . '%');
        }
    }
}
