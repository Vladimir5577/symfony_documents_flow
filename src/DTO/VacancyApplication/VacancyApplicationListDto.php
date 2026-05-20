<?php

declare(strict_types=1);

namespace App\DTO\VacancyApplication;

final readonly class VacancyApplicationListDto
{
    /**
     * @param VacancyApplicationDto[] $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $limit,
        public int $total,
        public int $pages,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $items = array_map(
            static fn(array $item) => VacancyApplicationDto::fromArray($item),
            $data['data'],
        );

        return new self(
            items: $items,
            page: (int) $data['pagination']['page'],
            limit: (int) $data['pagination']['limit'],
            total: (int) $data['pagination']['total'],
            pages: (int) $data['pagination']['pages'],
        );
    }
}
