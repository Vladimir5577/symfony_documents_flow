<?php

namespace App\Repository\Inventory;

use App\Entity\Inventory\Document;
use App\Enum\Inventory\DocumentStatus;
use App\Enum\Inventory\DocumentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Document>
 */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /**
     * @return array{documents: array<int, Document>, total: int, page: int, limit: int, totalPages: int}
     */
    public function findPaginated(
        int $page = 1,
        int $limit = 10,
        DocumentType|string|null $type = null,
        DocumentStatus|string|null $status = null,
        ?\DateTimeInterface $dateFrom = null,
        ?\DateTimeInterface $dateTo = null,
        ?string $search = null,
    ): array {
        $qb = $this->createQueryBuilder('d')
            ->orderBy('d.docDate', 'DESC')
            ->addOrderBy('d.id', 'DESC');

        $countQb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)');

        $typeEnum = \is_string($type) ? DocumentType::tryFrom($type) : $type;
        if ($typeEnum !== null) {
            $qb->andWhere('d.type = :type')->setParameter('type', $typeEnum);
            $countQb->andWhere('d.type = :type')->setParameter('type', $typeEnum);
        }

        $statusEnum = \is_string($status) ? DocumentStatus::tryFrom($status) : $status;
        if ($statusEnum !== null) {
            $qb->andWhere('d.status = :status')->setParameter('status', $statusEnum);
            $countQb->andWhere('d.status = :status')->setParameter('status', $statusEnum);
        }

        if ($dateFrom !== null) {
            $date = \DateTimeImmutable::createFromInterface($dateFrom);
            $qb->andWhere('d.docDate >= :dateFrom')->setParameter('dateFrom', $date, Types::DATE_IMMUTABLE);
            $countQb->andWhere('d.docDate >= :dateFrom')->setParameter('dateFrom', $date, Types::DATE_IMMUTABLE);
        }

        if ($dateTo !== null) {
            $date = \DateTimeImmutable::createFromInterface($dateTo);
            $qb->andWhere('d.docDate <= :dateTo')->setParameter('dateTo', $date, Types::DATE_IMMUTABLE);
            $countQb->andWhere('d.docDate <= :dateTo')->setParameter('dateTo', $date, Types::DATE_IMMUTABLE);
        }

        // Поиск по человекочитаемым идентификаторам документа: собственный номер,
        // номер УПД/акта и поставщик. У черновика номера ещё нет — он найдётся по реквизитам.
        $searchTerm = $search === null ? '' : trim($search);
        if ($searchTerm !== '') {
            $condition = '(LOWER(d.number) LIKE :search'
                . ' OR LOWER(d.externalNumber) LIKE :search'
                . ' OR LOWER(d.supplierName) LIKE :search)';
            $pattern = '%' . mb_strtolower($searchTerm) . '%';

            $qb->andWhere($condition)->setParameter('search', $pattern);
            $countQb->andWhere($condition)->setParameter('search', $pattern);
        }

        $total = (int) $countQb->getQuery()->getSingleScalarResult();
        $documents = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [
            'documents' => $documents,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $total > 0 ? (int) ceil($total / $limit) : 1,
        ];
    }
}
