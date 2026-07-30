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

        // Пока договор готовят — ошибочный файл можно снести и перезалить
        self::assertFalse($contract->isLockedAt(PurchaseStatus::CEO_APPROVED));
        self::assertFalse($contract->isLockedAt(PurchaseStatus::CONTRACT_PENDING));

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
        self::assertTrue($justification->isLockedAt(PurchaseStatus::NEW));
        self::assertTrue($justification->isLockedAt(PurchaseStatus::INVOICE_PAID));
    }

    public function testOptionalFilesNeverLocked(): void
    {
        foreach (PurchaseStatus::cases() as $status) {
            self::assertFalse(PurchaseFileType::TECHNICAL_SPEC->isLockedAt($status));
            self::assertFalse(PurchaseFileType::OTHER->isLockedAt($status));
        }
    }

    /** Цепочка исполнения проходит через договор и нигде не зацикливается. */
    public function testContractStageSitsBetweenApprovalAndPayment(): void
    {
        self::assertSame(PurchaseStatus::CONTRACT_PENDING, PurchaseStatus::CEO_APPROVED->nextExecutionStatus());
        self::assertSame(PurchaseStatus::INVOICE_SENT, PurchaseStatus::CONTRACT_PENDING->nextExecutionStatus());
    }
}
