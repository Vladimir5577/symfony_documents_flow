<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Тип налогообложения организации (РФ).
 */
enum TaxType: string
{
    case OSNO = 'osno';                    // ОСНО — общая система
    case USN_INCOME = 'usn_income';        // УСН доходы
    case USN_INCOME_EXPENSE = 'usn_income_expense'; // УСН доходы минус расходы
    case PSN = 'psn';                      // ПСН — патент
    case ESHN = 'eshn';                    // ЕСХН
    case NONE = 'none';                    // Не применяется / не указано

    public function getLabel(): string
    {
        return match ($this) {
            self::OSNO => 'ОСНО',
            self::USN_INCOME => 'УСН (доходы)',
            self::USN_INCOME_EXPENSE => 'УСН (доходы минус расходы)',
            self::PSN => 'ПСН (патент)',
            self::ESHN => 'ЕСХН',
            self::NONE => '—',
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
