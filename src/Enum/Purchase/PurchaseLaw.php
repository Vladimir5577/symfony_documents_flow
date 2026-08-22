<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

enum PurchaseLaw: string
{
    case FZ_44 = 'FZ_44';    // 44-ФЗ
    case FZ_223 = 'FZ_223';  // 223-ФЗ

    public function getLabel(): string
    {
        return match ($this) {
            self::FZ_44 => '44-ФЗ',
            self::FZ_223 => '223-ФЗ',
        };
    }
}
