<?php

declare(strict_types=1);

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseRouteTemplate;
use App\Enum\Purchase\PurchaseRequestKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
     * Стартовый шаблон под нажатую кнопку. NULL — шаблон не заведён;
     * подача такой заявки должна падать с ошибкой конфигурации, а не
     * молча собирать короткий маршрут (§2.3 плана).
     */
    public function findDefaultFor(PurchaseRequestKind $kind): ?PurchaseRouteTemplate
    {
        return $this->findOneBy(['isDefaultFor' => $kind, 'active' => true]);
    }

    /**
     * Заготовки и стартовые шаблоны для админки и переключателя маршрута.
     *
     * @return list<PurchaseRouteTemplate>
     */
    public function findAllOrdered(bool $onlyActive = false): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.steps', 's')
            ->addSelect('s')
            ->orderBy('t.name', 'ASC');

        if ($onlyActive) {
            $qb->andWhere('t.active = true');
        }

        return $qb->getQuery()->getResult();
    }
}
