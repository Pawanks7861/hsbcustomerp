<?php

namespace App\Support;

use InvalidArgumentException;

class Money
{
    public static function round(string $value, int $scale = 2): string
    {
        $value = trim($value);

        if ($value === '' || ! is_numeric($value)) {
            throw new InvalidArgumentException('Invalid decimal value.');
        }

        $increment = '0.'.str_repeat('0', $scale).'5';
        $negative = str_starts_with($value, '-');

        return $negative
            ? bcsub($value, $increment, $scale)
            : bcadd($value, $increment, $scale);
    }

    public static function mul(string $left, string $right, int $scale = 2): string
    {
        return self::round(bcmul($left, $right, $scale + 4), $scale);
    }

    public static function add(string $left, string $right, int $scale = 2): string
    {
        return bcadd($left, $right, $scale);
    }

    public static function sub(string $left, string $right, int $scale = 2): string
    {
        return bcsub($left, $right, $scale);
    }

    public static function percentage(string $base, string $percentage): string
    {
        $raw = bcmul($base, bcdiv($percentage, '100', 8), 8);

        return self::round($raw, 2);
    }

    public static function scale(null|string|int|float $value, int $scale): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::round(self::plain($value), $scale);
    }

    public static function itemAmount(null|string|int|float $quantity, null|string|int|float $rate): ?string
    {
        if ($quantity === null || $rate === null || $quantity === '' || $rate === '') {
            return null;
        }

        return self::mul(self::plain($quantity), self::plain($rate), 2);
    }

    private static function plain(string|int|float $value): string
    {
        $string = trim((string) $value);

        if ($string === '' || ! is_numeric($string)) {
            throw new InvalidArgumentException('Invalid decimal value.');
        }

        return $string;
    }
}
