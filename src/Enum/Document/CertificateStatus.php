<?php

declare(strict_types=1);

namespace App\Enum\Document;

enum CertificateStatus: string
{
    case ACTIVE = 'active';
    case REVOKED = 'revoked';

    public function getLabel(): string
    {
        return match ($this) {
            self::ACTIVE => 'Действителен',
            self::REVOKED => 'Отозван',
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
