<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

final class DocumentHistoricalRelationsLoader
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function load(Query $query): array
    {
        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('softdeleteable');
        if ($wasEnabled) {
            $filters->disable('softdeleteable');
        }

        try {
            // Запрос сам исключает удалённые документы; исторические связи читаем целиком.
            return $query->getResult();
        } finally {
            if ($wasEnabled) {
                $filters->enable('softdeleteable');
            }
        }
    }
}
