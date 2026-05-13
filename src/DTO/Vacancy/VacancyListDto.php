<?php

declare(strict_types=1);

namespace App\DTO\Vacancy;

final readonly class VacancyListDto
{
    /**
     * @param VacancyDto[] $items
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
            static fn(array $item) => VacancyDto::fromArray($item),
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
