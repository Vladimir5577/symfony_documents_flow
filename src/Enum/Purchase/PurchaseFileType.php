<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

enum PurchaseFileType: string
{
    case TECHNICAL_SPEC = 'TECHNICAL_SPEC';  // Техническое задание
    case JUSTIFICATION = 'JUSTIFICATION';    // Пояснительная записка
    case OTHER = 'OTHER';                    // Прочее

    public function getLabel(): string
    {
        return match ($this) {
            self::TECHNICAL_SPEC => 'Техническое задание',
            self::JUSTIFICATION => 'Пояснительная записка',
            self::OTHER => 'Прочее',
        };
    }
}
