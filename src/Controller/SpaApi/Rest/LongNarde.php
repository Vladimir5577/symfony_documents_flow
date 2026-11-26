<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Rest;

final class LongNarde
{
    public static function start(?int $first = null): array
    {
        $points = array_fill(0, 24, 0);
        $points[0] = 15;
        $points[12] = -15;

        return [
            'points' => $points, 'off' => [0, 0], 'turn' => $first ?? random_int(0, 1),
            'dice' => [], 'rolled' => [], 'headUsed' => 0, 'headLimit' => 1,
            'first' => [true, true], 'winner' => null, 'reason' => null, 'pointsWon' => null,
            'lastMoves' => [],
        ];
    }

    public static function roll(array $state, ?array $dice = null): array
    {
        $dice ??= [random_int(1, 6), random_int(1, 6)];
        $double = $dice[0] === $dice[1];
        $state['rolled'] = $dice;
        $state['dice'] = $double ? array_fill(0, 4, $dice[0]) : $dice;
        $state['headLimit'] = $state['first'][$state['turn']] && $double && in_array($dice[0], [3, 4, 6], true) ? 2 : 1;
        $state['lastMoves'] = [];

        return self::advanceIfBlocked($state);
    }

    /** @return list<array{from:int,to:int,die:int}> */
    public static function legalMoves(array $state): array
    {
        $best = 0;
        $bestDie = 0;
        $result = [];
        foreach (self::candidates($state) as $move) {
            $length = 1 + self::maxDice(self::apply($state, $move));
            $die = $length === 1 ? $move['die'] : 0;
            if ([$length, $die] > [$best, $bestDie]) {
                [$best, $bestDie] = [$length, $die];
                $result = [];
            }
            if ([$length, $die] === [$best, $bestDie]) {
                $result[] = $move;
            }
        }

        return $result;
    }

    public static function move(array $state, array $move): array
    {
        $state = self::apply($state, $move);
        $state['lastMoves'][] = $move;
        $side = $state['turn'];
        if ($state['off'][$side] === 15) {
            $state['winner'] = $side;
            $state['reason'] = 'borne_off';
            $state['pointsWon'] = $state['off'][1 - $side] === 0 ? 2 : 1;
            $state['dice'] = [];

            return $state;
        }

        return self::advanceIfBlocked($state);
    }

    public static function computerTurn(array $state): array
    {
        if ($state['turn'] !== 1 || $state['winner'] !== null) {
            return $state;
        }

        $state = self::roll($state);
        while ($state['turn'] === 1 && $state['winner'] === null) {
            $bestMove = null;
            $bestValue = -INF;
            foreach (self::legalMoves($state) as $move) {
                $value = self::evaluate(self::apply($state, $move));
                if ($value > $bestValue) {
                    $bestValue = $value;
                    $bestMove = $move;
                }
            }
            $state = self::move($state, $bestMove);
        }

        return $state;
    }

    private static function evaluate(array $state): float
    {
        $value = 28 * $state['off'][1];
        $run = 0;
        for ($progress = 0; $progress < 24; ++$progress) {
            $count = -$state['points'][($progress + 12) % 24];
            $run = $count > 0 ? $run + 1 : 0;
            if ($count > 0) {
                $value += $count * $progress + min(5, $run) * ($progress < 18 ? 1.8 : 0.5);
                $value -= max(0, $count - 3) * ($progress === 0 ? 2.0 : 0.6);
            }
        }

        return $value;
    }

    private static function advanceIfBlocked(array $state): array
    {
        if (self::candidates($state) === []) {
            $state['first'][$state['turn']] = false;
            $state['turn'] = 1 - $state['turn'];
            $state['dice'] = [];
            $state['headUsed'] = 0;
        }

        return $state;
    }

    private static function maxDice(array $state): int
    {
        $best = 0;
        $limit = count($state['dice']);
        foreach (self::candidates($state) as $move) {
            $best = max($best, 1 + self::maxDice(self::apply($state, $move)));
            if ($best === $limit) {
                break;
            }
        }

        return $best;
    }

    private static function candidates(array $state): array
    {
        if ($state['dice'] === []) {
            return [];
        }

        $side = $state['turn'];
        $head = $side * 12;
        $sign = 1 - 2 * $side;
        $points = $state['points'];
        $own = [];
        for ($progress = 0; $progress < 24; ++$progress) {
            if ($sign * $points[($head + $progress) % 24] > 0) {
                $own[] = $progress;
            }
        }

        $moves = [];
        foreach (array_unique($state['dice']) as $die) {
            foreach ($own as $progress) {
                if ($progress === 0 && $state['headUsed'] >= $state['headLimit']) {
                    continue;
                }
                $from = ($head + $progress) % 24;
                $destination = $progress + $die;
                if ($destination >= 24) {
                    if ($own[0] >= 18 && ($destination === 24 || $progress === $own[0])) {
                        $moves[] = ['from' => $from, 'to' => -1, 'die' => $die];
                    }
                    continue;
                }
                $to = ($head + $destination) % 24;
                if ($sign * $points[$to] < 0) {
                    continue;
                }
                if ($points[$to] === 0 && self::trapsAll($state, $from, $to, $sign)) {
                    continue;
                }
                $moves[] = ['from' => $from, 'to' => $to, 'die' => $die];
            }
        }

        return $moves;
    }

    private static function trapsAll(array $state, int $from, int $to, int $sign): bool
    {
        $opponent = 1 - $state['turn'];
        if ($state['off'][$opponent] > 0) {
            return false;
        }

        $points = $state['points'];
        $points[$from] -= $sign;
        $points[$to] += $sign;
        $run = 0;
        // Scan from the opponent's home back toward their head.
        for ($progress = 23; $progress >= 0; --$progress) {
            $count = $sign * $points[($opponent * 12 + $progress) % 24];
            if ($count < 0) {
                return false;
            }
            $run = $count > 0 ? $run + 1 : 0;
            if ($run === 6) {
                return true;
            }
        }

        return false;
    }

    private static function apply(array $state, array $move): array
    {
        $side = $state['turn'];
        $sign = 1 - 2 * $side;
        $state['points'][$move['from']] -= $sign;
        if ($move['to'] === -1) {
            ++$state['off'][$side];
        } else {
            $state['points'][$move['to']] += $sign;
        }
        if ($move['from'] === $side * 12) {
            ++$state['headUsed'];
        }
        array_splice($state['dice'], array_search($move['die'], $state['dice'], true), 1);

        return $state;
    }
}
