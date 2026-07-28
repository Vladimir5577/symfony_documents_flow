<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

/**
 * Статусы заявки на закупку — цепочка по схеме:
 * DRAFT → NEW → [APPROVERS_PENDING → APPROVERS_DONE] → CEO_APPROVE_PENDING
 *   → CEO_APPROVED → INVOICE_SENT → INVOICE_PAID → DELIVERED → DONE.
 * Этап согласантов пропускается, если они не назначены. REJECTED — возврат автору,
 * CANCELLED — отмена. Прогресс согласантов («2 из 5») хранится в PurchaseRequestApprover.
 */
enum PurchaseStatus: string
{
    case DRAFT = 'DRAFT';                              // Черновик — у автора
    case NEW = 'NEW';                                  // Подана — на рассмотрении отдела закупок
    case APPROVERS_PENDING = 'APPROVERS_PENDING';      // На согласовании у согласантов
    case APPROVERS_DONE = 'APPROVERS_DONE';            // Все согласанты подтвердили
    case CEO_APPROVE_PENDING = 'CEO_APPROVE_PENDING';  // У директора на согласовании
    case CEO_APPROVED = 'CEO_APPROVED';                // Согласовано директором
    case INVOICE_SENT = 'INVOICE_SENT';                // Счёт отправлен на оплату
    case INVOICE_PAID = 'INVOICE_PAID';                // Оплачено
    case DELIVERED = 'DELIVERED';                      // Доставлено
    case DONE = 'DONE';                                // Выполнено
    case REJECTED = 'REJECTED';                        // Возвращено на доработку
    case CANCELLED = 'CANCELLED';                      // Отменено

    public function getLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::NEW => 'Новая',
            self::APPROVERS_PENDING => 'На согласовании',
            self::APPROVERS_DONE => 'Согласанты подтвердили',
            self::CEO_APPROVE_PENDING => 'У директора',
            self::CEO_APPROVED => 'Согласовано',
            self::INVOICE_SENT => 'Счёт на оплате',
            self::INVOICE_PAID => 'Оплачено',
            self::DELIVERED => 'Доставлено',
            self::DONE => 'Выполнено',
            self::REJECTED => 'Возвращено на доработку',
            self::CANCELLED => 'Отменено',
        };
    }

    /**
     * Следующий шаг конвейера после согласования директором.
     * Исполнитель назначается при первом шаге (CEO_APPROVED → INVOICE_SENT).
     */
    public function nextExecutionStatus(): ?self
    {
        return match ($this) {
            self::CEO_APPROVED => self::INVOICE_SENT,
            self::INVOICE_SENT => self::INVOICE_PAID,
            self::INVOICE_PAID => self::DELIVERED,
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
     * Статусы, видимые отделу закупок (с момента подачи и дальше).
     * @return list<PurchaseStatus>
     */
    public static function getPurchaseDepartmentVisible(): array
    {
        return [
            self::NEW,
            self::APPROVERS_PENDING,
            self::APPROVERS_DONE,
            self::CEO_APPROVE_PENDING,
            self::CEO_APPROVED,
            self::INVOICE_SENT,
            self::INVOICE_PAID,
            self::DELIVERED,
            self::DONE,
            self::CANCELLED,
        ];
    }

    /**
     * Статусы, видимые директору: весь путь заявки, кроме черновиков.
     * Черновик — личная кухня автора, он ещё никому не подан.
     *
     * Согласует директор по-прежнему только со своего этапа (CEO_APPROVE_PENDING) —
     * это отдельная проверка в переходах, видимость на неё не влияет.
     * @return list<PurchaseStatus>
     */
    public static function getDirectorVisible(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $status): bool => $status !== self::DRAFT,
        ));
    }

    /**
     * Статусы, видимые плательщику (ROLE_PURCHASE_INVOICE): очередь на оплату и оплаченные.
     * @return list<PurchaseStatus>
     */
    public static function getPayerVisible(): array
    {
        return [
            self::INVOICE_SENT,
            self::INVOICE_PAID,
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
