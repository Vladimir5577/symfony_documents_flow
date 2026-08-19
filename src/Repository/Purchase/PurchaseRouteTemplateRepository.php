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
     * Заготовка вида заявки; NULL — её ни разу не сохраняли, действует маршрут
     * по умолчанию из кода.
     *
     * Шаги забираем тем же запросом: маршрут читается при каждой подаче и в
     * превью формы создания, а строк в нём единицы.
     */
    public function findByKind(PurchaseRequestKind $kind): ?PurchaseRouteTemplate
    {
        return $this->createQueryBuilder('t')
            ->addSelect('s')
            ->leftJoin('t.steps', 's')
            ->andWhere('t.kind = :kind')
            ->setParameter('kind', $kind)
            ->addOrderBy('s.position', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Заготовка для правки: создаём пустую при первом сохранении. Flush снаружи. */
    public function getOrCreate(PurchaseRequestKind $kind): PurchaseRouteTemplate
    {
        $template = $this->findByKind($kind);
        if ($template !== null) {
            return $template;
        }

        $template = (new PurchaseRouteTemplate())->setKind($kind);
        $this->getEntityManager()->persist($template);

        return $template;
    }
}
