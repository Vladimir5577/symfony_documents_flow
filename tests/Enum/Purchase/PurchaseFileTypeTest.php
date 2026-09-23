<?php

declare(strict_types=1);

namespace App\Tests\Enum\Purchase;

use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseStatus;
use PHPUnit\Framework\TestCase;

/**
 * Замок на обязательных вложениях: без него загрузивший мог удалить договор
 * у уже оплаченной заявки или УПД у закрытой.
 */
final class PurchaseFileTypeTest extends TestCase
{
    public function testContractLockedOncePaymentStarted(): void
    {
        $contract = PurchaseFileType::CONTRACT;

        // Пока идёт согласование и договор готовят — ошибочный файл можно снести и перезалить
        self::assertFalse($contract->isLockedAt(PurchaseStatus::ON_APPROVAL));
        self::assertFalse($contract->isLockedAt(PurchaseStatus::APPROVED));

        // Оплатили — договор зафиксирован
        self::assertTrue($contract->isLockedAt(PurchaseStatus::INVOICE_PAID));
        self::assertTrue($contract->isLockedAt(PurchaseStatus::DELIVERED));
    }

    public function testUpdIsNotLockedByStatus(): void
    {
        // УПД держит задача закрытия, не статус.
        self::assertFalse(PurchaseFileType::UPD->isLockedAt(PurchaseStatus::DELIVERED));
    }

    public function testOptionalFilesNeverLocked(): void
    {
        foreach (PurchaseStatus::cases() as $status) {
            self::assertFalse(PurchaseFileType::TECHNICAL_SPEC->isLockedAt($status));
            self::assertFalse(PurchaseFileType::OTHER->isLockedAt($status));
        }
    }

}
