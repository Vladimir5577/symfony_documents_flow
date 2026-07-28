<?php

namespace App\Repository\Inventory;

use App\Entity\Inventory\Nomenclature;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Nomenclature>
 */
class NomenclatureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Nomenclature::class);
    }

    /**
     * @return array{nomenclatures: array<int, Nomenclature>, total: int, page: int, limit: int, totalPages: int}
     */
    public function findPaginated(
        int $page = 1,
        int $limit = 10,
        string $search = '',
        ?int $categoryId = null,
        ?bool $isConsumable = null,
    ): array {
        $where = ['n.deleted_at IS NULL'];
        $parameters = [];
        $types = [];

        if ($search !== '') {
            $where[] = 'n.name ILIKE :search';
            $parameters['search'] = '%' . $search . '%';
            $types['search'] = ParameterType::STRING;
        }

        if ($categoryId !== null) {
            $where[] = 'n.category_id = :categoryId';
            $parameters['categoryId'] = $categoryId;
            $types['categoryId'] = ParameterType::INTEGER;
        }

        if ($isConsumable !== null) {
            $where[] = 'n.is_consumable = :isConsumable';
            $parameters['isConsumable'] = $isConsumable;
            $types['isConsumable'] = ParameterType::BOOLEAN;
        }

        $connection = $this->getEntityManager()->getConnection();
        $whereSql = implode(' AND ', $where);
        $total = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM inventory_nomenclature n WHERE ' . $whereSql,
            $parameters,
            $types,
        );

        $ids = $connection->executeQuery(
            'SELECT n.id FROM inventory_nomenclature n WHERE ' . $whereSql
                . ' ORDER BY n.name ASC, n.id ASC LIMIT :limit OFFSET :offset',
            $parameters + [
                'limit' => $limit,
                'offset' => ($page - 1) * $limit,
            ],
            $types + [
                'limit' => ParameterType::INTEGER,
                'offset' => ParameterType::INTEGER,
            ],
        )->fetchFirstColumn();

        $nomenclatures = $ids === []
            ? []
            : $this->createQueryBuilder('n')
                ->leftJoin('n.category', 'category')
                ->addSelect('category')
                ->where('n.id IN (:ids)')
                ->setParameter('ids', array_map('intval', $ids), ArrayParameterType::INTEGER)
                ->orderBy('n.name', 'ASC')
                ->addOrderBy('n.id', 'ASC')
                ->getQuery()
                ->getResult();

        return [
            'nomenclatures' => $nomenclatures,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $total > 0 ? (int) ceil($total / $limit) : 1,
        ];
    }
}
