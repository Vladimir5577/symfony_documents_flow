<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

enum PurchaseFileType: string
{
    case TECHNICAL_SPEC = 'TECHNICAL_SPEC';  // Техническое задание
    case CONTRACT = 'CONTRACT';              // Договор
    case UPD = 'UPD';                        // Универсальный передаточный документ
    case OTHER = 'OTHER';                    // Прочее

    public function getLabel(): string
    {
        return match ($this) {
            self::TECHNICAL_SPEC => 'Техническое задание',
            self::CONTRACT => 'Договор',
            self::UPD => 'УПД',
            self::OTHER => 'Прочее',
        };
    }

    /**
     * Вложение, без которого заявку не пропустили бы дальше, после прохождения его
     * стадии удалять нельзя: иначе оплаченная заявка останется без договора.
     * Пока стадия идёт, ошибочный файл можно снести и перезалить.
     */
    public function isLockedAt(PurchaseStatus $status): bool
    {
        return match ($this) {
            // Договор требуется, чтобы уйти на оплату.
            self::CONTRACT => in_array($status, [
                PurchaseStatus::INVOICE_PAID,
                PurchaseStatus::DELIVERED,
                PurchaseStatus::DONE,
            ], true),
            // УПД требуется, чтобы закрыть заявку.
            self::UPD => $status === PurchaseStatus::DONE,
            // ТЗ и прочее ни на что не завязаны.
            self::TECHNICAL_SPEC, self::OTHER => false,
        };
    }
}
