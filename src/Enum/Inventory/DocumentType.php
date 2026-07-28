<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

enum DocumentType: string
{
    case RECEIPT_UPD = 'receipt_upd';
    case INITIAL_BALANCE = 'initial_balance';
    case TRANSFER = 'transfer';
    case CONSUMPTION = 'consumption';
    case WRITEOFF = 'writeoff';
    case REVERSAL = 'reversal';

    public function getLabel(): string
    {
        return match ($this) {
            self::RECEIPT_UPD => 'Приход по УПД',
            self::INITIAL_BALANCE => 'Ввод остатков',
            self::TRANSFER => 'Перемещение',
            self::CONSUMPTION => 'Расход',
            self::WRITEOFF => 'Списание',
            self::REVERSAL => 'Сторно',
        };
    }

    public function numberPrefix(): string
    {
        return match ($this) {
            self::RECEIPT_UPD => 'ПР',
            self::INITIAL_BALANCE => 'ВО',
            self::TRANSFER => 'ПМ',
            self::CONSUMPTION => 'РХ',
            self::WRITEOFF => 'СП',
            self::REVERSAL => 'СТ',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function getChoices(): array
    {
        return array_column(
            array_map(static fn (self $case): array => [$case->value, $case->getLabel()], self::cases()),
            1,
            0,
        );
    }
}
