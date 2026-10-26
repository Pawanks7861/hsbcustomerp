<?php

namespace App\Support;

use App\Models\MaterialUnit;

class MaterialUnits
{
    /**
     * Built-in examples seeded into the unit master. Not a closed list.
     *
     * @return list<string>
     */
    public static function defaults(): array
    {
        return ['Nos', 'Kg', 'Bag', 'Litre', 'Meter', 'Cum', 'Sq Ft', 'Sq M', 'Ton', 'Roll', 'Box', 'Bundle'];
    }

    public static function normalize(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    }

    public static function slug(string $name): string
    {
        return mb_strtolower(self::normalize($name));
    }

    /**
     * @return list<string>
     */
    public static function names(bool $activeOnly = true): array
    {
        if (! class_exists(MaterialUnit::class)) {
            return self::defaults();
        }

        try {
            $query = MaterialUnit::query()->orderBy('name');
            if ($activeOnly) {
                $query->where('is_active', true);
            }

            $names = $query->pluck('name')->map(fn ($name) => (string) $name)->unique()->values()->all();

            return $names !== [] ? $names : self::defaults();
        } catch (\Throwable) {
            return self::defaults();
        }
    }

    public static function exists(string $name, bool $activeOnly = true): bool
    {
        $slug = self::slug($name);
        if ($slug === '') {
            return false;
        }

        try {
            $query = MaterialUnit::query()->where('slug', $slug);
            if ($activeOnly) {
                $query->where('is_active', true);
            }

            return $query->exists();
        } catch (\Throwable) {
            foreach (self::defaults() as $default) {
                if (self::slug($default) === $slug) {
                    return true;
                }
            }

            return false;
        }
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::names();
    }
}
