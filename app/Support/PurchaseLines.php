<?php

namespace App\Support;

class PurchaseLines
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function requestItems(array $rows): array
    {
        return array_map(function (array $row): array {
            $quantity = Money::scale($row['quantity'], 3);
            $rate = Money::scale($row['estimated_rate'] ?? null, 2);

            return [
                'description' => trim((string) $row['description']),
                'service_code' => self::nullable($row['service_code'] ?? null),
                'unit' => self::nullable($row['unit'] ?? null),
                'quantity' => $quantity,
                'estimated_rate' => $rate,
                'estimated_amount' => Money::itemAmount($quantity, $rate),
                'remarks' => self::nullable($row['remarks'] ?? null),
            ];
        }, array_values($rows));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function orderItems(array $rows): array
    {
        return array_map(function (array $row): array {
            $quantity = Money::scale($row['quantity'], 3);
            $rate = Money::scale($row['rate'], 2);

            return [
                'description' => trim((string) $row['description']),
                'unit' => self::nullable($row['unit'] ?? null),
                'quantity' => $quantity,
                'rate' => $rate,
                'amount' => Money::itemAmount($quantity, $rate),
                'remarks' => self::nullable($row['remarks'] ?? null),
            ];
        }, array_values($rows));
    }

    /**
     * @param  array<string, mixed>  $commercial
     * @return array{0: string, 1: string}
     */
    public static function rates(string|int|float|null $gstPercentage, array $commercial = []): array
    {
        $split = array_key_exists('cgst_percentage', $commercial) || array_key_exists('sgst_percentage', $commercial);

        if ($split) {
            return [
                Money::scale($commercial['cgst_percentage'] ?? 0, 2) ?? '0.00',
                Money::scale($commercial['sgst_percentage'] ?? 0, 2) ?? '0.00',
            ];
        }

        $gst = Money::scale($gstPercentage ?? 0, 2) ?? '0.00';
        $cgst = Money::round(bcdiv($gst, '2', 6), 2);

        return [$cgst, Money::sub($gst, $cgst)];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $commercial
     * @return array<int, array<string, mixed>>
     */
    public static function price(array $items, string|int|float|null $gstPercentage = null, array $commercial = []): array
    {
        [$cgstRate, $sgstRate] = self::rates($gstPercentage, $commercial);

        return array_map(function (array $item) use ($cgstRate, $sgstRate): array {
            $gross = Money::scale($item['amount'] ?? null, 2) ?? '0.00';
            $discountRate = Money::scale($item['discount_percentage'] ?? 0, 2) ?? '0.00';
            $discount = Money::percentage($gross, $discountRate);

            if (bccomp($discount, $gross, 2) === 1) {
                $discount = $gross;
            }

            $taxable = Money::sub($gross, $discount);
            $cgst = Money::percentage($taxable, $cgstRate);
            $sgst = Money::percentage($taxable, $sgstRate);

            $item['amount'] = $gross;
            $item['hsn_sac'] = self::nullable($item['hsn_sac'] ?? null);
            $item['discount_percentage'] = $discountRate;
            $item['discount_amount'] = $discount;
            $item['taxable_amount'] = $taxable;
            $item['cgst_amount'] = $cgst;
            $item['sgst_amount'] = $sgst;
            $item['line_total'] = Money::add(Money::add($taxable, $cgst), $sgst);

            return $item;
        }, array_values($items));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $commercial
     * @return array<string, string>
     */
    public static function summarize(array $items, string|int|float|null $gstPercentage = null, array $commercial = []): array
    {
        [$cgstRate, $sgstRate] = self::rates($gstPercentage, $commercial);
        $subtotal = '0.00';
        $discount = '0.00';
        $taxable = '0.00';
        $cgst = '0.00';
        $sgst = '0.00';

        foreach ($items as $item) {
            $subtotal = Money::add($subtotal, (string) ($item['amount'] ?? '0'));
            $discount = Money::add($discount, (string) ($item['discount_amount'] ?? '0'));
            $taxable = Money::add($taxable, (string) ($item['taxable_amount'] ?? $item['amount'] ?? '0'));
            $cgst = Money::add($cgst, (string) ($item['cgst_amount'] ?? '0'));
            $sgst = Money::add($sgst, (string) ($item['sgst_amount'] ?? '0'));
        }

        $freight = Money::scale($commercial['freight'] ?? 0, 2) ?? '0.00';
        $roundOff = Money::scale($commercial['round_off'] ?? 0, 2) ?? '0.00';
        $gstAmount = Money::add($cgst, $sgst);

        return [
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'taxable_value' => $taxable,
            'cgst_percentage' => $cgstRate,
            'sgst_percentage' => $sgstRate,
            'cgst_amount' => $cgst,
            'sgst_amount' => $sgst,
            'gst_percentage' => Money::add($cgstRate, $sgstRate),
            'gst_amount' => $gstAmount,
            'freight' => $freight,
            'round_off' => $roundOff,
            'grand_total' => Money::add(Money::add(Money::add($taxable, $gstAmount), $freight), $roundOff),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $commercial
     * @return array<string, string>
     */
    public static function totals(array $items, string|int|float|null $gstPercentage = null, array $commercial = []): array
    {
        return self::summarize(self::price($items, $gstPercentage, $commercial), $gstPercentage, $commercial);
    }

    private static function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
