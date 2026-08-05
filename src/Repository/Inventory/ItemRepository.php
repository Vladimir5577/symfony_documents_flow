<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\Item;
use App\Entity\Inventory\ItemCategory;
use App\Entity\Inventory\Upd;
use App\Entity\User\User;
use App\Enum\Inventory\ItemStatus;
use App\Service\Inventory\InventoryScope;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Item>
 */
class ItemRepository extends ServiceEntityRepository
{
    // Белый список: значение подставляется в DQL, произвольное поле сюда пускать нельзя.
    // `id` нужен, чтобы колонка ID в списке была кликабельной, — заодно это порядок,
    // в котором позиции лежат в выгрузке, по нему сверяют xlsx с экраном.
    private const SORTABLE = ['id', 'name', 'inventoryNumber', 'status', 'createdAt'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Item::class);
    }

    /**
     * @param array{
     *     organizationIds?: int[]|null,
     *     categoryId?: int|null,
     *     noCategory?: bool,
     *     status?: ItemStatus|null,
     *     assignedToId?: int|null,
     *     unassigned?: bool,
     *     assigneeFired?: bool,
     *     search?: string,
     *     sort?: string,
     *     direction?: string
     * } $filters
     *
     * @return array{items: Item[], total: int, page: int, limit: int, totalPages: int}
     */
    public function findPaginated(InventoryScope $scope, array $filters, int $page, int $limit): array
    {
        $qb = $this->createFilteredQueryBuilder($scope, $filters);
        $this->applySorting($qb, $filters);

        $total = (int) (clone $qb)
            ->resetDQLPart('orderBy')
            ->select('COUNT(DISTINCT i.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $qb
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
     * Те же скоуп и фильтры, что в списке, но без страниц — для выгрузки в xlsx.
     * Строится тем же билдером намеренно: выгрузка обязана отдавать ровно то,
     * что человек видит на экране, и не строкой больше.
     *
     * @param array<string, mixed> $filters
     *
     * @return Item[]
     */
    public function findFiltered(InventoryScope $scope, array $filters, int $limit): array
    {
        $qb = $this->createFilteredQueryBuilder($scope, $filters);
        $this->applySorting($qb, $filters);

        return $qb->setMaxResults($limit)->getQuery()->getResult();
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function countFiltered(InventoryScope $scope, array $filters): int
    {
        return (int) $this->createFilteredQueryBuilder($scope, $filters)
            ->select('COUNT(DISTINCT i.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function createFilteredQueryBuilder(InventoryScope $scope, array $filters): QueryBuilder
    {
        // Категория присоединяется левым join-ом: она необязательна, и внутренний
        // выкинул бы неразобранные товары из всех списков, ничего об этом не сказав.
        // Документ присоединяется сразу: и список, и выгрузка показывают его у каждой
        // строки, а ленивый прокси означал бы отдельный SELECT на каждый документ.
        $qb = $this->createQueryBuilder('i')
            ->addSelect('c', 'o', 'a', 'u')
            ->leftJoin('i.category', 'c')
            ->join('i.organization', 'o')
            ->leftJoin('i.assignedTo', 'a')
            ->leftJoin('i.upd', 'u');

        $this->applyScope($qb, $scope);
        $this->applyFilters($qb, $filters);

        return $qb;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applySorting(QueryBuilder $qb, array $filters): void
    {
        $sort = in_array($filters['sort'] ?? '', self::SORTABLE, true) ? $filters['sort'] : 'createdAt';
        $direction = strtoupper($filters['direction'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

        $qb->orderBy('i.' . $sort, $direction);

        // Добивка по id. Статус, наименование и даже createdAt (TIMESTAMP с точностью
        // до секунды) не уникальны, а без тай-брейкера PostgreSQL волен возвращать
        // равные строки в любом порядке — и при постраничном выводе одна и та же
        // позиция попала бы на две страницы подряд, а другая не попала бы никуда.
        if ($sort !== 'id') {
            $qb->addOrderBy('i.id', $direction);
        }
    }

    /**
     * Товары, назначенные на пользователя. Скоуп не применяется: свои вещи видит любой.
     *
     * @return Item[]
     */
    public function findAssignedTo(User $user): array
    {
        return $this->createQueryBuilder('i')
            ->addSelect('c', 'o')
            ->leftJoin('i.category', 'c')
            ->join('i.organization', 'o')
            ->andWhere('i.assignedTo = :user')
            ->setParameter('user', $user)
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Сколько товаров в категории — категорию с товарами удалять нельзя.
     */
    public function countByCategory(ItemCategory $category): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.category = :category')
            ->setParameter('category', $category)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Сколько позиций приехало по документу — документ с позициями удалять нельзя.
     */
    public function countByUpd(Upd $upd): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.upd = :upd')
            ->setParameter('upd', $upd)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Сколько товаров числится за организациями. Нужен перед удалением организации:
     * она удаляется мягко, поэтому ON DELETE RESTRICT на товарах не срабатывает.
     *
     * @param int[] $organizationIds
     */
    public function countByOrganizationIds(array $organizationIds): int
    {
        if ($organizationIds === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.organization IN (:organizationIds)')
            ->setParameter('organizationIds', $organizationIds)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function inventoryNumberExists(int $organizationId, string $inventoryNumber, ?int $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.organization = :organizationId')
            ->andWhere('i.inventoryNumber = :inventoryNumber')
            ->setParameter('organizationId', $organizationId)
            ->setParameter('inventoryNumber', $inventoryNumber);

        if ($exceptId !== null) {
            $qb->andWhere('i.id <> :exceptId')->setParameter('exceptId', $exceptId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Ограничение видимости: объединение по всем привязкам пользователя.
     * Админ организации видит в своём поддереве всё, ответственный — только свою категорию.
     */
    private function applyScope(QueryBuilder $qb, InventoryScope $scope): void
    {
        if ($scope->full) {
            return;
        }

        $or = $qb->expr()->orX();

        if ($scope->adminOrgIds !== []) {
            $or->add('i.organization IN (:scopeAdminOrgs)');
            $qb->setParameter('scopeAdminOrgs', $scope->adminOrgIds);
        }

        $index = 0;
        foreach ($scope->categoryOrgIds as $categoryId => $organizationIds) {
            if ($organizationIds === []) {
                continue;
            }

            $or->add(sprintf(
                '(i.category = :scopeCategory%1$d AND i.organization IN (:scopeCategoryOrgs%1$d))',
                $index,
            ));
            $qb->setParameter('scopeCategory' . $index, $categoryId);
            $qb->setParameter('scopeCategoryOrgs' . $index, $organizationIds);
            ++$index;
        }

        if ($or->count() === 0) {
            $qb->andWhere('1 = 0');

            return;
        }

        $qb->andWhere($or);
    }

    /**
     * Рассчитывает на алиасы из findPaginated: `i` — товар, `a` — левый join на владельца.
     */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['organizationIds'])) {
            $qb->andWhere('i.organization IN (:organizationIds)')
                ->setParameter('organizationIds', $filters['organizationIds']);
        }

        if (!empty($filters['noCategory'])) {
            $qb->andWhere('i.category IS NULL');
        } elseif (!empty($filters['categoryId'])) {
            $qb->andWhere('i.category = :categoryId')->setParameter('categoryId', $filters['categoryId']);
        }

        // Позиции одного документа — этим же фильтром карточка УПД показывает,
        // что по нему приехало, не дублируя форматирование товара у себя.
        if (!empty($filters['updId'])) {
            $qb->andWhere('i.upd = :updId')->setParameter('updId', $filters['updId']);
        }

        if (($filters['status'] ?? null) instanceof ItemStatus) {
            $qb->andWhere('i.status = :status')->setParameter('status', $filters['status']);
        }

        if (!empty($filters['unassigned'])) {
            $qb->andWhere('i.assignedTo IS NULL');
        } elseif (!empty($filters['assigneeFired'])) {
            // Внешний ключ заполнен, а сотрудник не подтянулся: его убрал глобальный
            // фильтр soft-delete, то есть человек уволен. Такой товар не попадает ни в
            // «мои товары», ни в «не присвоенные» — без этого фильтра он теряется совсем.
            $qb->andWhere('i.assignedTo IS NOT NULL')->andWhere('a.id IS NULL');
        } elseif (!empty($filters['assignedToId'])) {
            $qb->andWhere('i.assignedTo = :assignedToId')->setParameter('assignedToId', $filters['assignedToId']);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $qb->andWhere(
                'LOWER(i.name) LIKE LOWER(:search)'
                . ' OR LOWER(i.inventoryNumber) LIKE LOWER(:search)'
                . ' OR LOWER(i.serialNumber) LIKE LOWER(:search)',
            )->setParameter('search', '%' . $search . '%');
        }
    }
}
