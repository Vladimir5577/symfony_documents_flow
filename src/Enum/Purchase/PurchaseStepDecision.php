<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

/**
 * Решение по шагу маршрута. Необратимо: снять подтверждение нельзя —
 * позиция уже закрылась и следующим участникам ушли уведомления.
 * Передумал — это REJECTED с комментарием либо recall отделом закупок.
 */
enum PurchaseStepDecision: string
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
