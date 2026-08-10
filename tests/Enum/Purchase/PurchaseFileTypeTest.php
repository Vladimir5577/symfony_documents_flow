<?php

declare(strict_types=1);

namespace App\Tests\Enum\Purchase;

use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseStatus;
use PHPUnit\Framework\TestCase;

/**
 * Замок на обязательных вложениях: без него загрузивший мог удалить договор
 * у уже оплаченной заявки или записку у поданной.
 */
final class PurchaseFileTypeTest extends TestCase
{
    public function testContractLockedOncePaymentStarted(): void
    {
        $contract = PurchaseFileType::CONTRACT;

        // Пока идёт согласование и договор готовят — ошибочный файл можно снести и перезалить
        self::assertFalse($contract->isLockedAt(PurchaseStatus::ON_APPROVAL));
        self::assertFalse($contract->isLockedAt(PurchaseStatus::APPROVED));

        // Ушли на оплату — договор зафиксирован
        self::assertTrue($contract->isLockedAt(PurchaseStatus::INVOICE_SENT));
        self::assertTrue($contract->isLockedAt(PurchaseStatus::INVOICE_PAID));
        self::assertTrue($contract->isLockedAt(PurchaseStatus::DELIVERED));
        self::assertTrue($contract->isLockedAt(PurchaseStatus::DONE));
    }

    public function testJustificationLockedAfterSubmit(): void
    {
        $justification = PurchaseFileType::JUSTIFICATION;

        // Заявка редактируема (черновик, возврат на доработку) — записку можно менять
        self::assertFalse($justification->isLockedAt(PurchaseStatus::DRAFT));
        self::assertFalse($justification->isLockedAt(PurchaseStatus::REJECTED));

        // Подана — дальше заявка уже уехала на её основании
        self::assertTrue($justification->isLockedAt(PurchaseStatus::ON_APPROVAL));
        self::assertTrue($justification->isLockedAt(PurchaseStatus::INVOICE_PAID));
    }

    public function testOptionalFilesNeverLocked(): void
    {
        foreach (PurchaseStatus::cases() as $status) {
            self::assertFalse(PurchaseFileType::TECHNICAL_SPEC->isLockedAt($status));
            self::assertFalse(PurchaseFileType::OTHER->isLockedAt($status));
        }
    }

    /**
     * Конвейер исполнения начинается сразу со счёта: договор ушёл из статусов
     * в шаг маршрута. Если вернуть его сюда, быстрая заявка — у которой шага
     * «договор» нет — упрётся в требование договора и застрянет на APPROVED.
     */
    public function testExecutionStartsWithInvoiceNotContract(): void
    {
        self::assertSame(PurchaseStatus::INVOICE_SENT, PurchaseStatus::APPROVED->nextExecutionStatus());
        self::assertSame(PurchaseStatus::INVOICE_PAID, PurchaseStatus::INVOICE_SENT->nextExecutionStatus());
        self::assertSame(PurchaseStatus::DELIVERED, PurchaseStatus::INVOICE_PAID->nextExecutionStatus());
        self::assertNull(PurchaseStatus::DELIVERED->nextExecutionStatus());
    }
}
