<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

enum PurchaseMethod: string
{
    case COMPETITIVE = 'COMPETITIVE';          // Конкурсная
    case SINGLE_SUPPLIER = 'SINGLE_SUPPLIER';  // У единственного поставщика
    case DIRECT = 'DIRECT';                    // Прямая

    public function getLabel(): string
    {
        return match ($this) {
            self::COMPETITIVE => 'Конкурсная',
            self::SINGLE_SUPPLIER => 'У единственного поставщика',
            self::DIRECT => 'Прямая',
        };
    }

    /**
     * @return array<string, string> [value => label]
     */
    public static function getChoices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->value] = $case->getLabel();
        }
        return $choices;
    }
}
