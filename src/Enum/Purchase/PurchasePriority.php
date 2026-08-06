<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

enum PurchasePriority: string
{
    case NORMAL = 'NORMAL';   // Обычная
    case URGENT = 'URGENT';   // Срочная

    public function getLabel(): string
    {
        return match ($this) {
            self::NORMAL => 'Обычная',
            self::URGENT => 'Срочная',
        };
    }
}
