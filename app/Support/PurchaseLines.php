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

            $description = trim((string) $row['description']);
            $name = trim((string) ($row['name'] ?? ''));

            return [
                'name' => $name !== '' ? $name : $description,
                'description' => $description,
                'service_code' => self::nullable($row['service_code'] ?? null),
                'unit' => self::nullable($row['unit'] ?? null),
                'quantity' => $quantity,
                'estimated_rate' => null,
                'estimated_amount' => null,
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

            $description = trim((string) $row['description']);
            $name = trim((string) ($row['name'] ?? ''));

            return [
                'name' => $name !== '' ? $name : $description,
                'description' => $description,
                'unit' => self::nullable($row['unit'] ?? null),
                'quantity' => $quantity,
                'rate' => $rate,
                'amount' => Money::itemAmount($quantity, $rate),
                'remarks' => self::nullable($row['remarks'] ?? null),
            ];
        }, array_values($rows));
    }

    /**
     * Legacy helper: derive header CGST/SGST from a single GST rate.
     *
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

        [$cgst, $sgst] = array_slice(Tax::componentRates(
            Money::scale($gstPercentage ?? 0, 2) ?? '0.00',
            Tax::SPLIT_CGST_SGST
        ), 0, 2);

        return [$cgst, $sgst];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $commercial
     * @return array<int, array<string, mixed>>
     */
    public static function price(array $items, string|int|float|null $gstPercentage = null, array $commercial = []): array
    {
        $splitMode = (string) ($commercial['tax_split'] ?? Tax::SPLIT_CGST_SGST);
        if (! in_array($splitMode, [Tax::SPLIT_CGST_SGST, Tax::SPLIT_IGST], true)) {
            $splitMode = Tax::SPLIT_CGST_SGST;
        }

        $fallbackGst = self::fallbackGst($gstPercentage, $commercial);

        return array_map(function (array $item) use ($splitMode, $fallbackGst): array {
            $gross = Money::scale($item['amount'] ?? null, 2) ?? '0.00';
            $discountRate = Money::scale($item['discount_percentage'] ?? 0, 2) ?? '0.00';
            $discount = Money::percentage($gross, $discountRate);

            if (bccomp($discount, $gross, 2) === 1) {
                $discount = $gross;
            }

            $taxable = Money::sub($gross, $discount);
            $gst = self::itemGstRate($item, $fallbackGst);
            [$cgstRate, $sgstRate, $igstRate] = Tax::componentRates($gst, $splitMode);

            $cgst = Money::percentage($taxable, $cgstRate);
            $sgst = Money::percentage($taxable, $sgstRate);
            $igst = Money::percentage($taxable, $igstRate);
            $gstAmount = Money::add(Money::add($cgst, $sgst), $igst);

            $item['amount'] = $gross;
            $item['hsn_sac'] = self::nullable($item['hsn_sac'] ?? null);
            $item['discount_percentage'] = $discountRate;
            $item['discount_amount'] = $discount;
            $item['taxable_amount'] = $taxable;
            $item['gst_percentage'] = $gst;
            $item['cgst_percentage'] = $cgstRate;
            $item['sgst_percentage'] = $sgstRate;
            $item['igst_percentage'] = $igstRate;
            $item['cgst_amount'] = $cgst;
            $item['sgst_amount'] = $sgst;
            $item['igst_amount'] = $igst;
            $item['line_total'] = Money::add($taxable, $gstAmount);

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
        $subtotal = '0.00';
        $discount = '0.00';
        $taxable = '0.00';
        $cgst = '0.00';
        $sgst = '0.00';
        $igst = '0.00';
        $gstRates = [];
        $cgstRates = [];
        $sgstRates = [];
        $igstRates = [];

        foreach ($items as $item) {
            $subtotal = Money::add($subtotal, (string) ($item['amount'] ?? '0'));
            $discount = Money::add($discount, (string) ($item['discount_amount'] ?? '0'));
            $taxable = Money::add($taxable, (string) ($item['taxable_amount'] ?? $item['amount'] ?? '0'));
            $cgst = Money::add($cgst, (string) ($item['cgst_amount'] ?? '0'));
            $sgst = Money::add($sgst, (string) ($item['sgst_amount'] ?? '0'));
            $igst = Money::add($igst, (string) ($item['igst_amount'] ?? '0'));

            if (array_key_exists('gst_percentage', $item)) {
                $gstRates[] = Money::scale($item['gst_percentage'], 2) ?? '0.00';
            }
            if (array_key_exists('cgst_percentage', $item)) {
                $cgstRates[] = Money::scale($item['cgst_percentage'], 2) ?? '0.00';
            }
            if (array_key_exists('sgst_percentage', $item)) {
                $sgstRates[] = Money::scale($item['sgst_percentage'], 2) ?? '0.00';
            }
            if (array_key_exists('igst_percentage', $item)) {
                $igstRates[] = Money::scale($item['igst_percentage'], 2) ?? '0.00';
            }
        }

        $freight = Money::scale($commercial['freight'] ?? 0, 2) ?? '0.00';
        $roundOff = Money::scale($commercial['round_off'] ?? 0, 2) ?? '0.00';
        $gstAmount = Money::add(Money::add($cgst, $sgst), $igst);

        $uniformGst = self::uniformRate($gstRates);
        $uniformCgst = self::uniformRate($cgstRates);
        $uniformSgst = self::uniformRate($sgstRates);

        if ($uniformGst === null) {
            [$legacyCgst, $legacySgst] = self::rates($gstPercentage, $commercial);
            $headerCgst = $uniformCgst ?? $legacyCgst;
            $headerSgst = $uniformSgst ?? $legacySgst;
            $headerGst = Money::add($headerCgst, $headerSgst);
        } else {
            $headerGst = $uniformGst;
            $headerCgst = $uniformCgst ?? (Money::scale(bcdiv($uniformGst, '2', 6), 2) ?? '0.00');
            $headerSgst = $uniformSgst ?? Money::sub($uniformGst, $headerCgst);
        }

        if (bccomp($igst, '0', 2) === 1 && bccomp($cgst, '0', 2) === 0 && bccomp($sgst, '0', 2) === 0) {
            $headerCgst = '0.00';
            $headerSgst = '0.00';
        }

        return [
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'taxable_value' => $taxable,
            'cgst_percentage' => $headerCgst,
            'sgst_percentage' => $headerSgst,
            'cgst_amount' => $cgst,
            'sgst_amount' => $sgst,
            'igst_amount' => $igst,
            'gst_percentage' => $headerGst,
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

    /**
     * @param  array<string, mixed>  $commercial
     */
    private static function fallbackGst(string|int|float|null $gstPercentage, array $commercial): string
    {
        if (array_key_exists('cgst_percentage', $commercial) || array_key_exists('sgst_percentage', $commercial)) {
            $cgst = Money::scale($commercial['cgst_percentage'] ?? 0, 2) ?? '0.00';
            $sgst = Money::scale($commercial['sgst_percentage'] ?? 0, 2) ?? '0.00';

            return Money::add($cgst, $sgst);
        }

        return Money::scale($gstPercentage ?? 0, 2) ?? '0.00';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function itemGstRate(array $item, string $fallback): string
    {
        if (array_key_exists('gst_percentage', $item) && $item['gst_percentage'] !== null && $item['gst_percentage'] !== '') {
            return Money::scale($item['gst_percentage'], 2) ?? '0.00';
        }

        $cgst = array_key_exists('cgst_percentage', $item)
            ? (Money::scale($item['cgst_percentage'], 2) ?? '0.00')
            : null;
        $sgst = array_key_exists('sgst_percentage', $item)
            ? (Money::scale($item['sgst_percentage'], 2) ?? '0.00')
            : null;
        $igst = array_key_exists('igst_percentage', $item)
            ? (Money::scale($item['igst_percentage'], 2) ?? '0.00')
            : null;

        if ($igst !== null && bccomp($igst, '0', 2) === 1) {
            return $igst;
        }

        if ($cgst !== null || $sgst !== null) {
            return Money::add($cgst ?? '0.00', $sgst ?? '0.00');
        }

        return $fallback;
    }

    /**
     * @param  list<string>  $rates
     */
    private static function uniformRate(array $rates): ?string
    {
        if ($rates === []) {
            return null;
        }

        $unique = array_values(array_unique($rates));

        return count($unique) === 1 ? $unique[0] : '0.00';
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
