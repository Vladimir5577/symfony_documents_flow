<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

/**
 * Ключи настроек модуля закупок (таблица purchase_setting).
 *
 * Новая настройка = новый кейс здесь. Миграция не нужна: значения лежат
 * строками в ключ-значение таблице, дефолт берётся отсюда же.
 */
enum PurchaseSettingKey: string
{
    /** До какой суммы доступна короткая форма быстрой заявки. Правит отдел закупок. */
    case FAST_MAX_AMOUNT = 'fast_max_amount';

    public function getDefault(): mixed
    {
        return match ($this) {
            self::FAST_MAX_AMOUNT => 10000,
        };
    }

    /** Проверка значения перед записью: тип у каждого ключа свой, БД его не контролирует. */
    public function isValid(mixed $value): bool
    {
        return match ($this) {
            self::FAST_MAX_AMOUNT => is_numeric($value) && (float) $value >= 0,
        };
    }
}
