<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

/**
 * Состояние договора, а не заявки.
 *
 * В список для новой заявки попадает только действующий. Сумму никто не считает:
 * «исчерпан» ставит отдел закупок сам.
 */
enum PurchaseContractStatus: string
{
    case ACTIVE = 'ACTIVE';
    case VOID = 'VOID';
    case EXHAUSTED = 'EXHAUSTED';

    public function getLabel(): string
    {
        return match ($this) {
            self::ACTIVE => 'Действующий',
            self::VOID => 'Утратил силу',
            self::EXHAUSTED => 'Исчерпан по сумме',
        };
    }
}
