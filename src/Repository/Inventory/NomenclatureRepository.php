<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\ItemCategory;
use App\Entity\Inventory\Nomenclature;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Nomenclature>
 */
class NomenclatureRepository extends ServiceEntityRepository
{
    private const SORTABLE = [
        'name' => 'n.name',
        'createdAt' => 'n.createdAt',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Nomenclature::class);
    }

    /**
     * @param string|null $search подстрока в наименовании, регистр не важен
     * @param int|null    $limit  потолок строк; null — весь справочник
     *
     * @return Nomenclature[]
     */
    public function findAllOrdered(?string $search = null, ?int $limit = null): array
    {
        $qb = $this->createOrderedQueryBuilder($search);

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Как ItemRepository::findPaginated: счёт и срез страницы одним контрактом.
     *
     * @return array{items: Nomenclature[], total: int, page: int, limit: int, totalPages: int}
     */
    public function findPaginated(
        string $search,
        int $page,
        int $limit,
        string $sort = 'createdAt',
        string $direction = 'DESC',
    ): array {
        $field = self::SORTABLE[$sort] ?? self::SORTABLE['createdAt'];
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        $qb = $this->createOrderedQueryBuilder($search)
            ->orderBy($field, $direction)
            ->addOrderBy('n.id', $direction);

        $total = (int) (clone $qb)
            ->resetDQLPart('orderBy')
            ->select('COUNT(n.id)')
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
     * Категория присоединяется сразу: список показывает её у каждой строки,
     * а ленивый прокси означал бы отдельный SELECT на каждый вид.
     */
    private function createOrderedQueryBuilder(?string $search): QueryBuilder
    {
        $qb = $this->createQueryBuilder('n')
            ->addSelect('c')
            ->leftJoin('n.category', 'c')
            ->orderBy('n.name', 'ASC');

        $search = trim((string) $search);
        if ($search !== '') {
            // Поиск по подстроке, а не с начала строки: вид заводят как
            // «Монитор RDW2401K», а ищут по «RDW» или «2401».
            $qb->andWhere('LOWER(n.name) LIKE LOWER(:search)')
                ->setParameter('search', '%' . $search . '%');
        }

        return $qb;
    }

    /**
     * Занято ли имя без учёта регистра.
     *
     * В базе это гарантирует уникальный индекс по генерируемой колонке name_lower,
     * но полагаться только на него нельзя: пользователь получил бы 500 вместо
     * внятного 409 с указанием, какой вид уже есть.
     */
    public function findOneByName(string $name, ?int $exceptId = null): ?Nomenclature
    {
        $qb = $this->createQueryBuilder('n')
            ->andWhere('LOWER(n.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->setMaxResults(1);

        if ($exceptId !== null) {
            $qb->andWhere('n.id <> :exceptId')->setParameter('exceptId', $exceptId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Сколько видов заведено в категории — категорию с видами удалять нельзя,
     * иначе RESTRICT на внешнем ключе отдал бы наружу сырую ошибку драйвера.
     */
    public function countByCategory(ItemCategory $category): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.category = :category')
            ->setParameter('category', $category)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
