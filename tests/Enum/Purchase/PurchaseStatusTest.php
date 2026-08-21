<?php

declare(strict_types=1);

namespace App\Tests\Enum\Purchase;

use App\Enum\Purchase\PurchaseStagePurpose;
use App\Enum\Purchase\PurchaseStatus;
use PHPUnit\Framework\TestCase;

/**
 * Статус заявки как проекция маршрута.
 *
 * Заявка движется этапами, а статус только отражает, какой отрезок пройден.
 * Пока исполнение было отдельной цепочкой статусов со своими правилами «кому
 * можно», требование «доставку подтверждает склад, а не заявитель» стоило
 * релиза, тогда как «добавить в согласование охрану» стоило правки в админке.
 */
final class PurchaseStatusTest extends TestCase
{
    /**
     * Отдельного «счёт отправлен» в исполнении нет: счёт приходит в пакете
     * документов отдела закупок ещё внутри согласования, отмечать нечего.
     */
    public function testExecutionStagesProjectToStatuses(): void
    {
        self::assertSame(PurchaseStatus::INVOICE_PAID, PurchaseStatus::afterStage(PurchaseStagePurpose::PAYMENT));
        self::assertSame(PurchaseStatus::DELIVERED, PurchaseStatus::afterStage(PurchaseStagePurpose::DELIVERY));
        self::assertSame(PurchaseStatus::DONE, PurchaseStatus::afterStage(PurchaseStagePurpose::CLOSING));
    }

    /**
     * Этапы согласования статус не двигают: согласование целиком идёт внутри
     * ON_APPROVAL. APPROVED ставится, когда согласующая часть маршрута пройдена,
     * — сколько в ней было подписей и какие, значения не имеет.
     */
    public function testApprovalStagesDoNotMoveStatus(): void
    {
        self::assertNull(PurchaseStatus::afterStage(PurchaseStagePurpose::TRIAGE));
        self::assertNull(PurchaseStatus::afterStage(PurchaseStagePurpose::SOURCING));
        self::assertNull(PurchaseStatus::afterStage(PurchaseStagePurpose::SIGN_OFF));
    }

    /**
     * Решать по задачам можно и после APPROVED: маршрут на этом не кончается,
     * дальше идут этапы исполнения. Кончается он на DONE.
     */
    public function testRouteSpansApprovalAndExecution(): void
    {
        self::assertTrue(PurchaseStatus::ON_APPROVAL->isInRoute());
        self::assertTrue(PurchaseStatus::APPROVED->isInRoute());
        self::assertTrue(PurchaseStatus::INVOICE_PAID->isInRoute());
        self::assertTrue(PurchaseStatus::DELIVERED->isInRoute());

        self::assertFalse(PurchaseStatus::DRAFT->isInRoute());
        self::assertFalse(PurchaseStatus::REJECTED->isInRoute());
        self::assertFalse(PurchaseStatus::DONE->isInRoute());
        self::assertFalse(PurchaseStatus::CANCELLED->isInRoute());
    }
}
