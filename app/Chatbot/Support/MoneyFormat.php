<?php

namespace App\Chatbot\Support;

final class MoneyFormat
{
    public static function inr(mixed $value): string
    {
        $number = is_numeric($value) ? (float) $value : 0.0;

        return '₹'.number_format($number, 2, '.', ',');
    }

    public static function qty(mixed $value, ?string $unit = null): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $number = is_numeric($value) ? (float) $value : 0.0;
        $text = rtrim(rtrim(number_format($number, 3, '.', ','), '0'), '.');

        return $unit ? $text.' '.$unit : $text;
    }
}
