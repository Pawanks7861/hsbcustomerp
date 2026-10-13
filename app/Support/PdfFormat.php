<?php

namespace App\Support;

use Carbon\Carbon;

class PdfFormat
{
    public static function money(null|string|int|float $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $string = (string) $value;
        $negative = str_starts_with($string, '-');
        $string = ltrim($string, '-');
        [$whole, $fraction] = array_pad(explode('.', $string, 2), 2, '00');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);
        $last = substr($whole, -3);
        $rest = substr($whole, 0, -3);

        if ($rest !== '') {
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $whole = $rest.','.$last;
        }

        return ($negative ? '-' : '').$whole.'.'.$fraction;
    }

    public static function qty(null|string|int|float $value, ?string $unit = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $string = rtrim(rtrim((string) $value, '0'), '.');
        $string = $string === '' || $string === '-' ? '0' : $string;
        $unit = trim((string) $unit);

        return $unit === '' ? $string : $string.' '.$unit;
    }

    public static function date(null|string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        return Carbon::parse($value)->format('d M Y');
    }

    public static function amountInWords(null|string|int|float $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $string = (string) $value;
        $negative = str_starts_with($string, '-');
        $string = ltrim($string, '-');
        [$whole, $fraction] = array_pad(explode('.', $string, 2), 2, '00');
        $rupees = self::indianWords((int) $whole);
        $paise = (int) substr(str_pad($fraction, 2, '0'), 0, 2);
        $words = 'Rupees '.$rupees;

        if ($paise > 0) {
            $words .= ' and '.self::indianWords($paise).' Paise';
        }

        return ($negative ? 'Minus ' : '').$words.' Only';
    }

    public static function dateTime(null|string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        return Carbon::parse($value)->format('d M Y H:i');
    }

    private static function indianWords(int $number): string
    {
        if ($number === 0) {
            return 'Zero';
        }

        $scales = [
            10000000 => 'Crore',
            100000 => 'Lakh',
            1000 => 'Thousand',
            100 => 'Hundred',
        ];
        $parts = [];

        foreach ($scales as $scale => $label) {
            if ($number < $scale) {
                continue;
            }

            $count = intdiv($number, $scale);
            $number %= $scale;
            $parts[] = ($scale === 100 ? self::belowHundred($count) : self::indianWords($count)).' '.$label;
        }

        if ($number > 0) {
            $parts[] = self::belowHundred($number);
        }

        return implode(' ', $parts);
    }

    private static function belowHundred(int $number): string
    {
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        if ($number < 20) {
            return $ones[$number];
        }

        $word = $tens[intdiv($number, 10)];
        $rest = $number % 10;

        return $rest === 0 ? $word : $word.' '.$ones[$rest];
    }
}
