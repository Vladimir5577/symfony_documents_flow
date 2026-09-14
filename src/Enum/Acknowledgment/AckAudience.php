<?php

declare(strict_types=1);

namespace App\Enum\Acknowledgment;

/**
 * Кому адресован документ.
 *
 * ALL — живой список: все неудалённые пользователи на момент запроса. Список
 * нигде не материализуется, поэтому принятый завтра сотрудник попадает в него
 * сам, а уволенный выпадает сам.
 */
enum AckAudience: string
{
    case ALL = 'ALL';
    case SELECTED = 'SELECTED';

    public function getLabel(): string
    {
        return match ($this) {
            self::ALL => 'Все сотрудники',
            self::SELECTED => 'Выбранные сотрудники',
        };
    }
}
