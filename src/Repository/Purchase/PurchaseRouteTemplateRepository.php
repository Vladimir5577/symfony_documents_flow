<?php

declare(strict_types=1);

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseRouteTemplate;
use App\Enum\Purchase\PurchaseRequestKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseRouteTemplate>
 */
class PurchaseRouteTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseRouteTemplate::class);
    }

    /**
     * Заготовка целиком — с этапами и задачами.
     *
     * Дерево забираем одним запросом: маршрут читается при каждой подаче и в
     * превью формы создания, а строк в нём единицы.
     */
    public function findWithStages(int $id): ?PurchaseRouteTemplate
    {
        return $this->treeQuery()
            ->andWhere('t.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Заготовка по машинному имени — для фикстур и установки. */
    public function findByCode(string $code): ?PurchaseRouteTemplate
    {
        return $this->treeQuery()
            ->andWhere('t.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Все заготовки для админки — включая выключенные: их надо видеть, чтобы
     * включить обратно.
     *
     * @return list<PurchaseRouteTemplate>
     */
    public function findAllOrdered(): array
    {
        return $this->treeQuery()->getQuery()->getResult();
    }

    /**
     * Активные заготовки, разрешённые этому виду заявки — из них выбирают
     * маршрут для конкретной заявки.
     *
     * Совместимость по виду проверяем в PHP: allowed_kinds — это JSON, и запрос
     * по нему был бы непереносимым между СУБД ради выборки из десятка строк.
     *
     * @return list<PurchaseRouteTemplate>
     */
    public function findActiveForKind(PurchaseRequestKind $kind): array
    {
        $templates = $this->treeQuery()
            ->andWhere('t.active = true')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $templates,
            static fn (PurchaseRouteTemplate $t): bool => $t->allowsKind($kind) && !$t->isEmpty(),
        ));
    }

    /**
     * Заготовка вместе с деревом этапов и задач.
     *
     * Джойны здесь всегда: маршрут без этапов бесполезен ни в админке, ни в
     * превью, ни при подаче, а без них Doctrine добирала бы этапы по одному
     * запросу на заготовку.
     */
    private function treeQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('t')
            ->addSelect('st', 'ta')
            ->leftJoin('t.stages', 'st')
            ->leftJoin('st.tasks', 'ta')
            ->addOrderBy('t.sortOrder', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->addOrderBy('st.position', 'ASC')
            ->addOrderBy('ta.position', 'ASC')
            ->addOrderBy('ta.id', 'ASC');
    }
}
