<?php

namespace App\Repository\Inventory;

use App\Entity\Inventory\Room;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Room>
 */
class RoomRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Room::class);
    }

    /**
     * @return array{rooms: array<int, Room>, total: int, page: int, limit: int, totalPages: int}
     */
    public function findPaginated(int $page = 1, int $limit = 10, string $search = ''): array
    {
        $qb = $this->createQueryBuilder('r')->orderBy('r.name', 'ASC');
        if ($search !== '') {
            $qb->andWhere('LOWER(r.name) LIKE LOWER(:search) OR LOWER(COALESCE(r.building, \'\')) LIKE LOWER(:search)')
                ->setParameter('search', '%' . $search . '%');
        }

        $total = (int) (clone $qb)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

        return [
            'rooms' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $total > 0 ? (int) ceil($total / $limit) : 1,
        ];
    }
}
