<?php

declare(strict_types=1);

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseRouteDefault;
use App\Entity\Purchase\PurchaseRouteTemplate;
use App\Enum\Purchase\PurchaseRequestKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseRouteDefault>
 */
class PurchaseRouteDefaultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseRouteDefault::class);
    }

    /** Маршрут по умолчанию для вида заявки; NULL — дефолт не назначен. */
    public function findByKind(PurchaseRequestKind $kind): ?PurchaseRouteDefault
    {
        return $this->findOneBy(['kind' => $kind]);
    }

    /**
     * Виды заявок, для которых эта заготовка назначена дефолтной.
     *
     * Нужно перед выключением заготовки: маршрут, из-под которого убрали
     * дефолт, оставил бы вид заявки без маршрута, и подача просто перестала бы
     * работать — без внятной причины для того, кто нажал «выключить».
     *
     * @return list<PurchaseRequestKind>
     */
    public function kindsDefaultingTo(PurchaseRouteTemplate $template): array
    {
        $rows = $this->findBy(['template' => $template]);

        return array_values(array_filter(array_map(
            static fn (PurchaseRouteDefault $row): ?PurchaseRequestKind => $row->getKind(),
            $rows,
        )));
    }

    /** Назначить дефолт, создав запись при первом назначении. Flush снаружи. */
    public function set(PurchaseRequestKind $kind, PurchaseRouteTemplate $template): PurchaseRouteDefault
    {
        $row = $this->findByKind($kind);
        if ($row === null) {
            $row = (new PurchaseRouteDefault())->setKind($kind);
            $this->getEntityManager()->persist($row);
        }

        return $row->setTemplate($template);
    }
}
