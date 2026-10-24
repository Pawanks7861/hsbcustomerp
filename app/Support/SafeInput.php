<?php

namespace App\Support;

final class SafeInput
{
    /**
     * Strip null bytes and C0 control characters while preserving Unicode,
     * punctuation, and newlines/tabs used in addresses and notes.
     */
    public static function plainText(string $value): string
    {
        $value = str_replace("\0", '', $value);

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim(self::plainText($email)));
    }

    /**
     * Neutralize spreadsheet formula injection without dropping the original value.
     */
    public static function spreadsheetCell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        $first = $value[0];

        if (in_array($first, ['=', '+', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        if ($first === '-' && ! preg_match('/^-\d/', $value)) {
            return "'".$value;
        }

        return $value;
    }
}
