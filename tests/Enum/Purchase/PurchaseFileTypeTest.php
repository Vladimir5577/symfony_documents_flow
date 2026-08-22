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
        self::assertTrue($contract->isLockedAt(PurchaseStatus::DONE));
    }

    public function testUpdLockedOnceRequestClosed(): void
    {
        $upd = PurchaseFileType::UPD;

        // До закрытия УПД можно перезалить: заявку без него всё равно не закрыть
        self::assertFalse($upd->isLockedAt(PurchaseStatus::DELIVERED));

        // Закрыли в архив — единственное подтверждение закупки трогать нельзя
        self::assertTrue($upd->isLockedAt(PurchaseStatus::DONE));
    }

    public function testOptionalFilesNeverLocked(): void
    {
        foreach (PurchaseStatus::cases() as $status) {
            self::assertFalse(PurchaseFileType::TECHNICAL_SPEC->isLockedAt($status));
            self::assertFalse(PurchaseFileType::OTHER->isLockedAt($status));
        }
    }

}
