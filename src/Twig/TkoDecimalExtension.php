<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class TkoDecimalExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('tko_decimal', [self::class, 'format']),
        ];
    }

    public static function format(mixed $value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        $raw = str_replace(',', '.', trim((string) $value));
        if ('' === $raw || !is_numeric($raw)) {
            return (string) $value;
        }

        if (!str_contains($raw, '.')) {
            return $raw;
        }

        $trimmed = rtrim(rtrim($raw, '0'), '.');

        return '' === $trimmed || '-' === $trimmed ? '0' : $trimmed;
    }
}
