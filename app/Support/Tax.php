<?php

namespace App\Support;

class Tax
{
    public const SPLIT_CGST_SGST = 'cgst_sgst';

    public const SPLIT_IGST = 'igst';

    /**
     * @return list<string>
     */
    public static function gstRates(): array
    {
        $configured = config('tax.gst_rates', ['0.00', '5.00', '12.00', '18.00', '28.00']);

        return array_values(array_unique(array_map(
            static fn ($rate) => Money::scale($rate, 2) ?? '0.00',
            is_array($configured) ? $configured : ['0', '5', '12', '18', '28']
        )));
    }

    public static function isAllowedRate(string|int|float|null $rate): bool
    {
        $scaled = Money::scale($rate, 2);

        if ($scaled === null) {
            return false;
        }

        return in_array($scaled, self::gstRates(), true);
    }

    public static function splitMode(?string $placeOfSupply, ?string $companyState): string
    {
        $supply = self::normalizeRegion($placeOfSupply);
        $company = self::normalizeRegion($companyState);

        if ($supply === '' || $company === '') {
            return self::SPLIT_CGST_SGST;
        }

        return $supply === $company ? self::SPLIT_CGST_SGST : self::SPLIT_IGST;
    }

    public static function normalizeRegion(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = preg_replace('/\b(state|ut|union territory)\b/', '', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return array{0: string, 1: string, 2: string} CGST%, SGST%, IGST%
     */
    public static function componentRates(string $gstPercentage, string $splitMode): array
    {
        $gst = Money::scale($gstPercentage, 2) ?? '0.00';

        if ($splitMode === self::SPLIT_IGST) {
            return ['0.00', '0.00', $gst];
        }

        $cgst = Money::round(bcdiv($gst, '2', 6), 2);

        return [$cgst, Money::sub($gst, $cgst), '0.00'];
    }
}
