<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

/**
 * Решение по задаче этапа.
 *
 * Сбрасывается в PENDING только вместе с этапом — при возврате в закупки или
 * отзыве подписи, и всегда с записью в историю: сгоревшее согласование не должно
 * исчезнуть бесследно, потому что в самой задаче от него не остаётся ничего.
 */
enum PurchaseTaskDecision: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';

    public function isDecided(): bool
    {
        return $this !== self::PENDING;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает',
            self::APPROVED => 'Согласовано',
            self::REJECTED => 'Возвращено',
        };
    }
}
