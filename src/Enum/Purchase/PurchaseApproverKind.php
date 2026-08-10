<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

/**
 * Чем адресован шаг маршрута.
 *
 * CATEGORY_RESPONSIBLE существует только в шаблоне: при построении маршрута
 * строитель разворачивает его в 0..N шагов вида USER по ответственным
 * за категории заявки. В шагах конкретной заявки этого вида уже нет.
 */
enum PurchaseApproverKind: string
{
    case ROLE = 'ROLE';                                  // закроет любой носитель роли
    case USER = 'USER';                                  // закроет конкретный человек
    case CATEGORY_RESPONSIBLE = 'CATEGORY_RESPONSIBLE';  // ответственные за категории заявки

    public function getLabel(): string
    {
        return match ($this) {
            self::ROLE => 'Роль',
            self::USER => 'Сотрудник',
            self::CATEGORY_RESPONSIBLE => 'Ответственные за категорию',
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
