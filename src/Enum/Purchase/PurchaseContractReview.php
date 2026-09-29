<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

/**
 * Что задача делает с договором.
 *
 * Рецензирование — оставить правки своего отдела. Утверждение рецензий —
 * принять или отклонить правки предыдущих шагов. null — договор эту задачу
 * не касается.
 */
enum PurchaseContractReview: string
{
    case REVIEW = 'REVIEW';
    case ACCEPT = 'ACCEPT';

    public function getLabel(): string
    {
        return match ($this) {
            self::REVIEW => 'Рецензирование',
            self::ACCEPT => 'Утверждение рецензий',
        };
    }
}
