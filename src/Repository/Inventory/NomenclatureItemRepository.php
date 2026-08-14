<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\ItemCategory;
use App\Entity\Inventory\NomenclatureItem;
use App\Entity\Inventory\Upd;
use App\Entity\User\User;
use App\Enum\Inventory\ItemStatus;
use App\Service\Inventory\InventoryScope;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NomenclatureItem>
 */
class NomenclatureItemRepository extends ServiceEntityRepository
{
    /**
     * Белый список сортировок: значение подставляется в DQL, произвольное поле сюда
     * пускать нельзя. Наименование живёт на виде, поэтому у него другой алиас.
     * `id` нужен, чтобы колонка ID в списке была кликабельной, — заодно это порядок,
     * в котором позиции лежат в выгрузке, по нему сверяют xlsx с экраном.
     */
    private const SORTABLE = [
        'id' => 'i.id',
        'name' => 'n.name',
        'inventoryNumber' => 'i.inventoryNumber',
        'status' => 'i.status',
        'createdAt' => 'i.createdAt',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NomenclatureItem::class);
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
     * @return array{items: NomenclatureItem[], total: int, page: int, limit: int, totalPages: int}
     */
    public function findPaginated(InventoryScope $scope, array $filters, int $page, int $limit): array
    {
        $qb = $this->createFilteredQueryBuilder($scope, $filters);
        $this->applySorting($qb, $filters);

        return $this->paginate($qb, $page, $limit);
    }

    /**
     * Счёт и срез страницы. Общее для списка и «моего имущества»: расчёт числа
     * страниц, разложенный по двум местам, однажды разъехался бы.
     *
     * @return array{items: NomenclatureItem[], total: int, page: int, limit: int, totalPages: int}
     */
    private function paginate(QueryBuilder $qb, int $page, int $limit): array
    {
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
     * @return NomenclatureItem[]
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
        // Вид присоединяется внутренним join-ом: он обязателен у каждой позиции,
        // и наименование в списке берётся оттуда. Категория и организация — левыми:
        // обе необязательны, и внутренний молча выкинул бы неразобранные позиции
        // из всех списков. Документ присоединяется сразу: и список, и выгрузка
        // показывают его у каждой строки, а ленивый прокси означал бы отдельный
        // SELECT на каждый документ. Работник владельца (`aw`) в ответ не идёт, но
        // джойн обязателен: worker — инверсная сторона OneToOne у User, прокси на неё
        // построить нельзя. Без джойна страница с разными владельцами стоит
        // +20 запросов, а выгрузка — до +10000.
        $qb = $this->createQueryBuilder('i')
            ->addSelect('n', 'c', 'o', 'a', 'aw', 'u')
            ->join('i.nomenclature', 'n')
            ->leftJoin('n.category', 'c')
            ->leftJoin('i.organization', 'o')
            ->leftJoin('i.user', 'a')
            ->leftJoin('a.worker', 'aw')
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
        $field = self::SORTABLE[$filters['sort'] ?? ''] ?? self::SORTABLE['createdAt'];
        $direction = strtoupper($filters['direction'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

        $qb->orderBy($field, $direction);

        // Добивка по id. Статус, наименование и даже createdAt (TIMESTAMP с точностью
        // до секунды) не уникальны, а без тай-брейкера PostgreSQL волен возвращать
        // равные строки в любом порядке — и при постраничном выводе одна и та же
        // позиция попала бы на две страницы подряд, а другая не попала бы никуда.
        if ($field !== 'i.id') {
            $qb->addOrderBy('i.id', $direction);
        }
    }

    /**
     * Позиции, назначенные на пользователя. Скоуп не применяется: свои вещи видит любой.
     *
     * Постранично и с поиском: на подотчёте у кладовщика бывают тысячи позиций, и
     * отдавать их одним куском нельзя. Скоуп сюда не передаётся намеренно — условие
     * по владельцу зашито прямо здесь, и подменить его запросом снаружи нельзя.
     *
     * @return array{items: NomenclatureItem[], total: int, page: int, limit: int, totalPages: int}
     */
    public function findAssignedTo(User $user, string $search, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('i')
            ->addSelect('n', 'c', 'o')
            ->join('i.nomenclature', 'n')
            ->leftJoin('n.category', 'c')
            ->leftJoin('i.organization', 'o')
            ->andWhere('i.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.name', 'ASC')
            // Добивка по id — та же причина, что в applySorting: наименования не
            // уникальны, и без тай-брейкера строки разъезжались бы между страницами.
            ->addOrderBy('i.id', 'ASC');

        $this->applySearch($qb, $search);

        return $this->paginate($qb, $page, $limit);
    }

    /**
     * Сколько позиций в категории — категорию с имуществом удалять нельзя.
     * Категория висит на виде, поэтому считаем через join, а не по своему полю.
     */
    public function countByCategory(ItemCategory $category): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->join('i.nomenclature', 'n')
            ->andWhere('n.category = :category')
            ->setParameter('category', $category)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Счётчики для списка видов: сколько всего штук и сколько из них у людей.
     *
     * Одним запросом с группировкой, а не по запросу на вид: справочник живёт сотнями
     * строк, и счёт в цикле стоил бы сотни SELECT-ов на открытие экрана.
     *
     * Списанные не считаются: «сколько у нас мониторов» — вопрос про то, чем можно
     * пользоваться, а списанный монитор остаётся в базе только ради истории.
     *
     * @return array<int, array{total: int, assigned: int}> ключ — id вида
     */
    public function countGroupedByNomenclature(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select(
                'IDENTITY(i.nomenclature) AS nomenclatureId',
                'COUNT(i.id) AS total',
                'SUM(CASE WHEN i.user IS NULL THEN 0 ELSE 1 END) AS assigned',
            )
            ->andWhere('i.status <> :writtenOff')
            ->setParameter('writtenOff', ItemStatus::WRITTEN_OFF)
            ->groupBy('i.nomenclature')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['nomenclatureId']] = [
                'total' => (int) $row['total'],
                'assigned' => (int) $row['assigned'],
            ];
        }

        return $counts;
    }

    /**
     * Сколько позиций заведено на вид — вид с имуществом удалять нельзя.
     */
    public function countByNomenclature(int $nomenclatureId): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.nomenclature = :nomenclature')
            ->setParameter('nomenclature', $nomenclatureId)
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
     * Сколько позиций числится за организациями. Нужен перед удалением организации:
     * она удаляется мягко, поэтому ON DELETE RESTRICT на позициях не срабатывает.
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

    /**
     * Занят ли инвентарный номер. Организация необязательна, и позиции без неё
     * проверяются между собой: у них номер защищён отдельным партиальным индексом,
     * а `= NULL` в SQL не сравнивается ни с чем и молча пропустил бы дубль.
     */
    public function inventoryNumberExists(?int $organizationId, string $inventoryNumber, ?int $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.inventoryNumber = :inventoryNumber')
            ->setParameter('inventoryNumber', $inventoryNumber);

        if ($organizationId === null) {
            $qb->andWhere('i.organization IS NULL');
        } else {
            $qb->andWhere('i.organization = :organizationId')->setParameter('organizationId', $organizationId);
        }

        if ($exceptId !== null) {
            $qb->andWhere('i.id <> :exceptId')->setParameter('exceptId', $exceptId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Ограничение видимости: объединение по всем привязкам пользователя.
     * Админ организации видит в своём поддереве всё, ответственный — только свою
     * категорию. Категория теперь на виде, поэтому условие идёт по алиасу `n`.
     *
     * Позиции без организации не видит никто, кроме главного администратора:
     * NULL не совпадает с IN, и такая строка не пройдёт ни одну ветку ниже.
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
                '(n.category = :scopeCategory%1$d AND i.organization IN (:scopeCategoryOrgs%1$d))',
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
     * Рассчитывает на алиасы из createFilteredQueryBuilder: `i` — позиция,
     * `n` — вид, `a` — левый join на владельца.
     */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['organizationIds'])) {
            $qb->andWhere('i.organization IN (:organizationIds)')
                ->setParameter('organizationIds', $filters['organizationIds']);
        }

        if (!empty($filters['noCategory'])) {
            $qb->andWhere('n.category IS NULL');
        } elseif (!empty($filters['categoryId'])) {
            $qb->andWhere('n.category = :categoryId')->setParameter('categoryId', $filters['categoryId']);
        }

        if (!empty($filters['nomenclatureId'])) {
            $qb->andWhere('i.nomenclature = :nomenclatureId')
                ->setParameter('nomenclatureId', $filters['nomenclatureId']);
        }

        // Позиции одного документа — этим же фильтром карточка УПД показывает,
        // что по нему приехало, не дублируя форматирование позиции у себя.
        if (!empty($filters['updId'])) {
            $qb->andWhere('i.upd = :updId')->setParameter('updId', $filters['updId']);
        }

        if (($filters['status'] ?? null) instanceof ItemStatus) {
            $qb->andWhere('i.status = :status')->setParameter('status', $filters['status']);
        }

        if (!empty($filters['unassigned'])) {
            $qb->andWhere('i.user IS NULL');
        } elseif (!empty($filters['assigneeFired'])) {
            // Внешний ключ заполнен, а сотрудник не подтянулся: его убрал глобальный
            // фильтр soft-delete, то есть человек уволен. Такая позиция не попадает ни в
            // «мои товары», ни в «не присвоенные» — без этого фильтра она теряется совсем.
            $qb->andWhere('i.user IS NOT NULL')->andWhere('a.id IS NULL');
        } elseif (!empty($filters['assignedToId'])) {
            $qb->andWhere('i.user = :assignedToId')->setParameter('assignedToId', $filters['assignedToId']);
        }

        $this->applySearch($qb, (string) ($filters['search'] ?? ''));
    }

    /**
     * Поиск по наименованию вида, инвентарному и серийному номеру. Вынесен отдельно,
     * потому что им пользуются и общий список, и «моё имущество»: два скопированных
     * поиска однажды начали бы искать по разным полям.
     *
     * Наименование ищется по виду — ровно ради этого справочник и заводился:
     * одна опечатка в карточке больше не прячет позицию от поиска.
     */
    private function applySearch(QueryBuilder $qb, string $search): void
    {
        $search = trim($search);
        if ($search === '') {
            return;
        }

        $qb->andWhere(
            'LOWER(n.name) LIKE LOWER(:search)'
            . ' OR LOWER(i.inventoryNumber) LIKE LOWER(:search)'
            . ' OR LOWER(i.serialNumber) LIKE LOWER(:search)',
        )->setParameter('search', '%' . $search . '%');
    }
}
