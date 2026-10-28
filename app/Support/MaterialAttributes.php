<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class MaterialAttributes
{
    public const CONSUMABLE = 'consumable';

    public const ASSET = 'asset';

    /**
     * @return list<string>
     */
    public static function classifications(): array
    {
        return [self::CONSUMABLE, self::ASSET];
    }

    public static function normalizeClassification(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            '', 'null' => null,
            'asset', 'assets', 'fixed asset', 'fixed assets', 'equipment' => self::ASSET,
            'consumable', 'consumables', 'stock' => self::CONSUMABLE,
            default => $value,
        };
    }

    public static function assertClassification(?string $value): string
    {
        $normalized = self::normalizeClassification($value);

        if ($normalized === null) {
            return self::CONSUMABLE;
        }

        if (! in_array($normalized, self::classifications(), true)) {
            throw ValidationException::withMessages([
                'classification' => 'Classification must be Asset or Consumable.',
            ]);
        }

        return $normalized;
    }

    public static function normalizePrice(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $price = Money::scale($value, 2);

        if ($price !== null && bccomp($price, '0', 2) === -1) {
            throw ValidationException::withMessages([
                'default_unit_price' => 'Unit price cannot be negative.',
            ]);
        }

        return $price;
    }

    public static function normalizeUnitCost(mixed $value, string $field = 'unit_cost'): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $cost = Money::scale($value, 2);

        if ($cost !== null && bccomp($cost, '0', 2) === -1) {
            throw ValidationException::withMessages([
                $field => 'Unit cost cannot be negative.',
            ]);
        }

        return $cost;
    }

    public static function normalizeUsefulLife(mixed $value, string $classification): ?int
    {
        if ($classification !== self::ASSET) {
            return null;
        }

        if ($value === null || $value === '') {
            throw ValidationException::withMessages([
                'useful_life_years' => 'Useful life in years is required for assets.',
            ]);
        }

        if (! is_numeric($value) || (int) $value != $value || (int) $value < 1 || (int) $value > 100) {
            throw ValidationException::withMessages([
                'useful_life_years' => 'Useful life must be a whole number of years between 1 and 100.',
            ]);
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{data: array<string, mixed>, errors: list<string>}
     */
    public static function parseImport(array $data): array
    {
        $errors = [];

        try {
            $data['default_unit_price'] = array_key_exists('default_unit_price', $data)
                ? self::normalizePrice($data['default_unit_price'])
                : ($data['default_unit_price'] ?? null);
        } catch (ValidationException) {
            $errors[] = 'Default unit price cannot be negative.';
        }

        try {
            if (array_key_exists('unit_cost', $data)) {
                $data['unit_cost'] = self::normalizeUnitCost($data['unit_cost']);
            }
        } catch (ValidationException) {
            $errors[] = 'Unit cost cannot be negative.';
        }

        $classification = trim((string) ($data['classification'] ?? ''));
        $life = $data['useful_life_years'] ?? null;
        if ($classification !== '' || ($life !== null && $life !== '')) {
            try {
                $data['classification'] = $classification !== ''
                    ? self::assertClassification($classification)
                    : self::ASSET;
                $data['useful_life_years'] = self::normalizeUsefulLife($life, $data['classification']);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $messages) {
                    foreach ($messages as $message) {
                        $errors[] = $message;
                    }
                }
            }
        } else {
            unset($data['classification'], $data['useful_life_years']);
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * @return array<string, mixed>
     */
    public static function masterFields(array $data): array
    {
        return array_filter([
            'default_unit_price' => $data['default_unit_price'] ?? null,
            'classification' => $data['classification'] ?? null,
            'useful_life_years' => array_key_exists('useful_life_years', $data) ? $data['useful_life_years'] : null,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
