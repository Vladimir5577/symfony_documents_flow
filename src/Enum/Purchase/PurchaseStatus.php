<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

enum PurchaseStatus: string
{
    case DRAFT = 'DRAFT';                        // Черновик
    case PENDING_APPROVAL = 'PENDING_APPROVAL';  // На согласовании
    case APPROVED = 'APPROVED';                  // Согласовано
    case REJECTED = 'REJECTED';                  // Возвращено на доработку
    case IN_PROGRESS = 'IN_PROGRESS';            // В работе
    case AWAITING_PAYMENT = 'AWAITING_PAYMENT';  // Счёт на оплате
    case PAID = 'PAID';                          // Оплачено
    case DELIVERED = 'DELIVERED';                // Доставлено
    case DONE = 'DONE';                          // Выполнено
    case CANCELLED = 'CANCELLED';                // Отменено

    public function getLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::PENDING_APPROVAL => 'На согласовании',
            self::APPROVED => 'Согласовано',
            self::REJECTED => 'Возвращено на доработку',
            self::IN_PROGRESS => 'В работе',
            self::AWAITING_PAYMENT => 'Счёт на оплате',
            self::PAID => 'Оплачено',
            self::DELIVERED => 'Доставлено',
            self::DONE => 'Выполнено',
            self::CANCELLED => 'Отменено',
        };
    }

    /**
     * Следующий шаг конвейера отдела закупок.
     * APPROVED → IN_PROGRESS выполняется отдельным действием take (назначает executor).
     */
    public function nextExecutionStatus(): ?self
    {
        return match ($this) {
            self::IN_PROGRESS => self::AWAITING_PAYMENT,
            self::AWAITING_PAYMENT => self::PAID,
            self::PAID => self::DELIVERED,
            default => null,
        };
    }

    public function isFinal(): bool
    {
        return $this === self::DONE || $this === self::CANCELLED;
    }

    /** Автор может редактировать заявку только в этих статусах. */
    public function isEditable(): bool
    {
        return $this === self::DRAFT || $this === self::REJECTED;
    }

    /**
     * Статусы, видимые отделу закупок (всё согласованное и дальше).
     * @return list<PurchaseStatus>
     */
    public static function getPurchaseDepartmentVisible(): array
    {
        return [
            self::APPROVED,
            self::IN_PROGRESS,
            self::AWAITING_PAYMENT,
            self::PAID,
            self::DELIVERED,
            self::DONE,
            self::CANCELLED,
        ];
    }

    /**
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
