<?php

declare(strict_types=1);

namespace App\Enum;

enum OrganizationType: string
{
    case ORGANIZATION = 'organization';
    case FILIAL = 'filial';
    case DEPARTMENT = 'department';

    public function getLabel(): string
    {
        return match ($this) {
            self::ORGANIZATION => 'Организация',
            self::FILIAL => 'Филиал',
            self::DEPARTMENT => 'Департамент',
        };
    }

    public function requiresParent(): bool
    {
        return $this === self::FILIAL || $this === self::DEPARTMENT;
    }

    /**
     * Варианты для выбора в формах.
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
