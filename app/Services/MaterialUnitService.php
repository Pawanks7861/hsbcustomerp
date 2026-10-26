<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialUnit;
use App\Support\MaterialUnits;
use App\Support\SafeInput;
use Illuminate\Validation\ValidationException;

class MaterialUnitService
{
    public function create(string $name, ?int $userId = null): MaterialUnit
    {
        $normalized = MaterialUnits::normalize(SafeInput::plainText($name));

        if ($normalized === '' || mb_strlen($normalized) > 50) {
            throw ValidationException::withMessages([
                'name' => $normalized === '' ? 'Enter a unit name.' : 'Unit names cannot exceed 50 characters.',
            ]);
        }

        $slug = MaterialUnits::slug($normalized);
        $existing = MaterialUnit::query()->where('slug', $slug)->first();

        if ($existing) {
            if (! $existing->is_active) {
                $existing->update(['is_active' => true, 'name' => $normalized]);
            }

            return $existing->fresh();
        }

        return MaterialUnit::query()->create([
            'name' => $normalized,
            'slug' => $slug,
            'is_active' => true,
            'created_by' => $userId,
        ]);
    }

    public function deactivate(MaterialUnit $unit): void
    {
        if (Material::query()->whereRaw('LOWER(unit) = ?', [$unit->slug])->exists()) {
            throw ValidationException::withMessages([
                'unit' => 'This unit is used on materials and cannot be deleted. Deactivate it instead.',
            ]);
        }

        $unit->update(['is_active' => false]);
    }
}
