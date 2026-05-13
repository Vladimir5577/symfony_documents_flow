<?php

declare(strict_types=1);

namespace App\DTO\ContractApplication;

final readonly class ContractApplicationListDto
{
    /**
     * @param ContractApplicationDto[] $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $limit,
        public int $total,
        public int $pages,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $items = array_map(
            static fn(array $item) => ContractApplicationDto::fromArray($item),
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
