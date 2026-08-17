<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

/** Чем адресован шаг маршрута. */
enum PurchaseApproverKind: string
{
    case ROLE = 'ROLE';  // закроет любой носитель роли
    case USER = 'USER';  // закроет конкретный человек

    public function getLabel(): string
    {
        return match ($this) {
            self::ROLE => 'Роль',
            self::USER => 'Сотрудник',
        };
    }

    /** @return array<string, string> [value => label] */
    public static function getChoices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->value] = $case->getLabel();
        }

        return $choices;
    }
}
