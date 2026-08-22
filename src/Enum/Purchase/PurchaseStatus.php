<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

/**
 * Статусы заявки на закупку.
 *
 * Статус — проекция маршрута, а не его движок. Весь путь заявки, включая оплату,
 * поставку и закрытие, идёт этапами (PurchaseApprovalStage); статус лишь отмечает,
 * какой отрезок пройден, чтобы списки, счётчики и фильтры не поднимали этапы
 * каждой строки. «У кого заявка» — данные этапа, а не статус, поэтому новый
 * согласующий не требует нового статуса.
 *
 * Раньше отрезок после согласования был отдельным механизмом: цепочка статусов со
 * своими правилами «кому можно». Из-за этого «добавить в согласование охрану»
 * стоило правки в админке, а «доставку подтверждает склад, а не заявитель» —
 * релиза. Теперь оба требования одного порядка и стоят одинаково.
 *
 * Проекция: этапы согласования → ON_APPROVAL, пройдены все → APPROVED,
 * пройден этап оплаты → INVOICE_PAID, поставки → DELIVERED, закрытия → DONE.
 * Маршрут из одних подписей заканчивается на APPROVED — это законная настройка,
 * а не зависшая заявка: согласование состоялось, исполнения в регламенте нет.
 *
 * Записывает статус только PurchaseApprovalWorkflow: две записи одной правды
 * расходятся тем быстрее, чем больше у них авторов.
 */
enum PurchaseStatus: string
{
    case DRAFT = 'DRAFT';                  // Черновик — у автора
    case ON_APPROVAL = 'ON_APPROVAL';      // Идёт по маршруту
    case APPROVED = 'APPROVED';            // Согласование пройдено
    case INVOICE_PAID = 'INVOICE_PAID';    // Оплачено
    case DELIVERED = 'DELIVERED';          // Доставлено
    case DONE = 'DONE';                    // Закрыто в архив
    case REJECTED = 'REJECTED';            // Возвращено на доработку
    case CANCELLED = 'CANCELLED';          // Отменено

    public function getLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::ON_APPROVAL => 'На согласовании',
            self::APPROVED => 'Согласовано',
            self::INVOICE_PAID => 'Оплачено',
            self::DELIVERED => 'Доставлено',
            self::DONE => 'Выполнено',
            self::REJECTED => 'Возвращено на доработку',
            self::CANCELLED => 'Отменено',
        };
    }

    /**
     * Какой статус означает, что этап такого назначения пройден.
     *
     * NULL — этап согласования: заявка остаётся на согласовании, пока маршрут не
     * дойдёт до конца согласующей части.
     */
    public static function afterStage(PurchaseStagePurpose $purpose): ?self
    {
        return match ($purpose) {
            PurchaseStagePurpose::PAYMENT => self::INVOICE_PAID,
            PurchaseStagePurpose::DELIVERY => self::DELIVERED,
            PurchaseStagePurpose::CLOSING => self::DONE,
            PurchaseStagePurpose::TRIAGE,
            PurchaseStagePurpose::SOURCING,
            PurchaseStagePurpose::SIGN_OFF => null,
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

    /** Заявка идёт по маршруту: этапы согласования или исполнения ещё не пройдены. */
    public function isInRoute(): bool
    {
        return match ($this) {
            self::ON_APPROVAL, self::APPROVED, self::INVOICE_PAID, self::DELIVERED => true,
            self::DRAFT, self::DONE, self::REJECTED, self::CANCELLED => false,
        };
    }

    /**
     * Что видит носитель полномочия «видеть все заявки»: весь путь заявки,
     * кроме чужих черновиков — заявки ещё нет, есть замысел автора.
     *
     * Право согласовать это не даёт: его даёт только задача маршрута.
     *
     * @return list<PurchaseStatus>
     */
    public static function getNonDraft(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $status): bool => $status !== self::DRAFT,
        ));
    }
}
