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
    /** Заявки дешевле этой суммы не идут к директору: 0 — выключено, все идут. */
    case CEO_APPROVE_MIN_AMOUNT = 'ceo_approve_min_amount';

    /** Значение, действующее пока настройку не сохранили через интерфейс. */
    public function getDefault(): mixed
    {
        return match ($this) {
            self::CEO_APPROVE_MIN_AMOUNT => 10000,
        };
    }

    /** Проверка значения перед записью: тип у каждого ключа свой, БД его не контролирует. */
    public function isValid(mixed $value): bool
    {
        return match ($this) {
            self::CEO_APPROVE_MIN_AMOUNT => is_numeric($value) && (float) $value >= 0,
        };
    }
}
