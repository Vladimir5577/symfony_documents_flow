<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

/**
 * Кнопка, которой создана заявка, и привязка стартового шаблона маршрута.
 *
 * У заявки лежит в created_as и НЕ меняется: отдел закупок волен переключить
 * маршрут на заготовку (route_template_id), но форма, которую заполнял автор,
 * и потолок быстрой заявки считаются по этому полю.
 */
enum PurchaseRequestKind: string
{
    case FAST = 'FAST';          // короткая форма, потолок fast_max_amount
    case STANDARD = 'STANDARD';  // полная форма

    public function getLabel(): string
    {
        return match ($this) {
            self::FAST => 'Быстрая',
            self::STANDARD => 'Обычная',
        };
    }
}
