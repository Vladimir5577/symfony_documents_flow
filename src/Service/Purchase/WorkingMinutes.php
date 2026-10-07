<?php

declare(strict_types=1);

namespace App\Service\Purchase;

/**
 * Рабочие минуты: будни 08:00–12:00 и 12:30–16:30, кроме перечисленных дат.
 *
 * 12:00 и 16:30 уже не рабочие. Суббота и воскресенье не считаются.
 * Часы всегда Europe/Moscow (UTC+3, без перевода стрелок), даже если дата пришла в UTC.
 */
final class WorkingMinutes
{
    private const ZONE = 'Europe/Moscow';
    private const START = 8 * 3600;
    private const BREAK_START = 12 * 3600;
    private const BREAK_END = 12 * 3600 + 30 * 60;
    private const END = 16 * 3600 + 30 * 60;

    /**
     * Нерабочие будни. Суббота и воскресенье пропускаются отдельно.
     * 2027 — производственный календарь, переносы уже учтены.
     * ponytail: список вручную. Следующий год — дописать даты.
     */
    private const HOLIDAYS = [
        '2026-11-04',
        '2026-12-31',
        '2027-01-01',
        '2027-01-04',
        '2027-01-05',
        '2027-01-06',
        '2027-01-07',
        '2027-01-08',
        '2027-02-22',
        '2027-02-23',
        '2027-03-08',
        '2027-05-03',
        '2027-05-10',
        '2027-06-14',
        '2027-11-04',
        '2027-11-05',
        '2027-12-31',
    ];

    /** Суббота 20.02.2027 рабочая: выходной перенесён на 22.02. */
    private const WORKING_WEEKENDS = ['2027-02-20'];

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::ZONE));
    }

    public static function add(\DateTimeImmutable $from, int $minutes): \DateTimeImmutable
    {
        $from = $from->setTimezone(new \DateTimeZone(self::ZONE));
        $left = $minutes * 60;
        if ($left <= 0) {
            return $from;
        }

        $cursor = self::align($from);
        while (true) {
            $end = self::windowEnd($cursor);
            $room = $end->getTimestamp() - $cursor->getTimestamp();
            if ($left <= $room) {
                return $cursor->modify('+' . $left . ' seconds');
            }
            $left -= $room;
            $cursor = self::after($end);
        }
    }

    private static function align(\DateTimeImmutable $at): \DateTimeImmutable
    {
        if (self::isOff($at)) {
            return self::nextDay($at);
        }

        $seconds = ((int) $at->format('H')) * 3600 + ((int) $at->format('i')) * 60 + (int) $at->format('s');
        if ($seconds < self::START) {
            return $at->setTime(8, 0, 0);
        }
        if ($seconds >= self::BREAK_START && $seconds < self::BREAK_END) {
            return $at->setTime(12, 30, 0);
        }
        if ($seconds >= self::END) {
            return self::nextDay($at);
        }

        return $at;
    }

    /** Конец текущего отрезка: 12:00 до обеда, 16:30 после. */
    private static function windowEnd(\DateTimeImmutable $inside): \DateTimeImmutable
    {
        $seconds = ((int) $inside->format('H')) * 3600 + ((int) $inside->format('i')) * 60 + (int) $inside->format('s');

        return $seconds < self::BREAK_START
            ? $inside->setTime(12, 0, 0)
            : $inside->setTime(16, 30, 0);
    }

    /** Следующий отрезок после конца текущего: обед — с 12:30, вечер — со следующего утра. */
    private static function after(\DateTimeImmutable $windowEnd): \DateTimeImmutable
    {
        $seconds = ((int) $windowEnd->format('H')) * 3600 + ((int) $windowEnd->format('i')) * 60;

        return $seconds === self::BREAK_START
            ? $windowEnd->setTime(12, 30, 0)
            : self::nextDay($windowEnd);
    }

    private static function nextDay(\DateTimeImmutable $at): \DateTimeImmutable
    {
        $next = $at->modify('+1 day')->setTime(8, 0, 0);
        while (self::isOff($next)) {
            $next = $next->modify('+1 day')->setTime(8, 0, 0);
        }

        return $next;
    }

    private static function isOff(\DateTimeImmutable $at): bool
    {
        $date = $at->format('Y-m-d');
        if (in_array($date, self::WORKING_WEEKENDS, true)) {
            return false;
        }

        return (int) $at->format('N') >= 6 || in_array($date, self::HOLIDAYS, true);
    }
}
