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
    /** От какой суммы шаг директора обязателен в маршруте. Правит директор. 0 — всегда обязателен. */
    case CEO_APPROVE_MIN_AMOUNT = 'ceo_approve_min_amount';

    /** До какой суммы доступна короткая форма быстрой заявки. Правит отдел закупок. */
    case FAST_MAX_AMOUNT = 'fast_max_amount';

    /**
     * Числа независимы и друг друга не ограничивают: потолок быстрых 20 000
     * при пороге директора 5 000 — штатная ситуация, быстрая заявка на 8 000
     * идёт по короткой форме и получает шаг директора. «Быстрая» — это форма,
     * а не длина маршрута.
     */
    public function getDefault(): mixed
    {
        return match ($this) {
            self::CEO_APPROVE_MIN_AMOUNT => 10000,
            self::FAST_MAX_AMOUNT => 10000,
        };
    }

    /** Проверка значения перед записью: тип у каждого ключа свой, БД его не контролирует. */
    public function isValid(mixed $value): bool
    {
        return match ($this) {
            self::CEO_APPROVE_MIN_AMOUNT,
            self::FAST_MAX_AMOUNT => is_numeric($value) && (float) $value >= 0,
        };
    }
}
