<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

/**
 * Статусы заявки на закупку.
 *
 * Согласование статусами больше не описывается: весь его отрезок — отдел
 * закупок, согласанты, директор, подготовка договора, финдиректор — это шаги
 * маршрута (PurchaseApprovalStep), а заявка всё это время стоит в ON_APPROVAL.
 * «У кого заявка» — данные шага, а не статус. Поэтому добавление нового
 * согласующего больше не требует нового статуса.
 *
 * Цепочка: DRAFT → ON_APPROVAL → APPROVED → INVOICE_SENT → INVOICE_PAID
 *   → DELIVERED → DONE; REJECTED — возврат автору (и снова ON_APPROVAL при
 *   повторной подаче), CANCELLED — отмена.
 */
enum PurchaseStatus: string
{
    case DRAFT = 'DRAFT';                  // Черновик — у автора
    case ON_APPROVAL = 'ON_APPROVAL';      // Идёт по маршруту согласования
    case APPROVED = 'APPROVED';            // Маршрут пройден, можно исполнять
    case INVOICE_SENT = 'INVOICE_SENT';    // Счёт отправлен на оплату
    case INVOICE_PAID = 'INVOICE_PAID';    // Оплачено
    case DELIVERED = 'DELIVERED';          // Доставлено
    case DONE = 'DONE';                    // Выполнено
    case REJECTED = 'REJECTED';            // Возвращено на доработку
    case CANCELLED = 'CANCELLED';          // Отменено

    public function getLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::ON_APPROVAL => 'На согласовании',
            self::APPROVED => 'Согласовано',
            self::INVOICE_SENT => 'Счёт на оплате',
            self::INVOICE_PAID => 'Оплачено',
            self::DELIVERED => 'Доставлено',
            self::DONE => 'Выполнено',
            self::REJECTED => 'Возвращено на доработку',
            self::CANCELLED => 'Отменено',
        };
    }

    /**
     * Следующий шаг конвейера исполнения. Договора здесь нет — он стал шагом
     * маршрута, и файл требует тот шаг, а не переход.
     * Исполнитель назначается при первом шаге (APPROVED → INVOICE_SENT).
     */
    public function nextExecutionStatus(): ?self
    {
        return match ($this) {
            self::APPROVED => self::INVOICE_SENT,
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
     * Статусы, видимые отделу закупок: с момента подачи и дальше.
     * @return list<PurchaseStatus>
     */
    public static function getPurchaseDepartmentVisible(): array
    {
        return [
            self::ON_APPROVAL,
            self::APPROVED,
            self::INVOICE_SENT,
            self::INVOICE_PAID,
            self::DELIVERED,
            self::DONE,
            self::CANCELLED,
        ];
    }

    /**
     * Статусы, видимые директору и финдиректору: весь путь, кроме чужих
     * черновиков. Право согласовать при этом даёт только шаг маршрута —
     * видимость на него не влияет.
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
