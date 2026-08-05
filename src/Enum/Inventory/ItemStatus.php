<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

enum ItemStatus: string
{
    case IN_STOCK = 'in_stock';
    case IN_USE = 'in_use';
    case UNDER_REPAIR = 'under_repair';
    case WRITTEN_OFF = 'written_off';

    public function getLabel(): string
    {
        return match ($this) {
            self::IN_STOCK => 'На складе',
            self::IN_USE => 'В работе',
            self::UNDER_REPAIR => 'В ремонте',
            self::WRITTEN_OFF => 'Списан',
        };
    }
}
