<?php

declare(strict_types=1);

namespace App\Enum\Document;

enum SignatureLevel: string
{
    case SIMPLE = 'simple';      // ПЭП
    case ENHANCED = 'enhanced';  // УНЭП

    public function getLabel(): string
    {
        return match ($this) {
            self::SIMPLE => 'Простая подпись',
            self::ENHANCED => 'Усиленная подпись',
        };
    }

    /**
     * @return array<string, string>
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
