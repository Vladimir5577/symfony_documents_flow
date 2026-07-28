<?php

namespace App\Repository\Inventory;

use App\Entity\Inventory\DocCounter;
use App\Enum\Inventory\DocumentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocCounter>
 */
class DocCounterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocCounter::class);
    }

    public function nextNumber(DocumentType $documentType, int $year): int
    {
        $connection = $this->getEntityManager()->getConnection();

        $increment = static function (Connection $connection) use ($documentType, $year): int {
            $parameters = [
                'docType' => $documentType->value,
                'year' => $year,
            ];
            $types = [
                'docType' => ParameterType::STRING,
                'year' => ParameterType::INTEGER,
            ];

            $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO inventory_doc_counter (doc_type, year, last_no)
                    VALUES (:docType, :year, 0)
                    ON CONFLICT (doc_type, year) DO NOTHING
                    SQL,
                $parameters,
                $types,
            );

            $lastNumber = $connection->executeQuery(
                <<<'SQL'
                    SELECT last_no
                    FROM inventory_doc_counter
                    WHERE doc_type = :docType AND year = :year
                    FOR UPDATE
                    SQL,
                $parameters,
                $types,
            )->fetchOne();

            $nextNumber = (int) $lastNumber + 1;

            $connection->executeStatement(
                <<<'SQL'
                    UPDATE inventory_doc_counter
                    SET last_no = :lastNo
                    WHERE doc_type = :docType AND year = :year
                    SQL,
                $parameters + ['lastNo' => $nextNumber],
                $types + ['lastNo' => ParameterType::INTEGER],
            );

            return $nextNumber;
        };

        return $connection->transactional($increment);
    }
}
