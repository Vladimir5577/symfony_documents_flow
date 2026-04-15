<?php

declare(strict_types=1);

namespace App\Enum;

enum DocumentRecipientRole: string
{
    case EXECUTOR = 'executor';
    case RECIPIENT = 'recipient';

    public function getLabel(): string
    {
        return match ($this) {
            self::EXECUTOR => 'Исполнитель',
            self::RECIPIENT => 'Получатель',
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
