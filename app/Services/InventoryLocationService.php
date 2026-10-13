<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class InventoryLocationService
{
    public function ensureInTransit(): InventoryLocation
    {
        return InventoryLocation::query()->firstOrCreate(
            ['location_code' => 'IN-TRANSIT'],
            [
                'name' => 'In Transit',
                'location_type' => InventoryLocation::IN_TRANSIT,
                'is_active' => true,
                'is_default' => false,
                'is_system' => true,
            ]
        );
    }

    public function ensureProjectStore(Project $project): InventoryLocation
    {
        $existing = InventoryLocation::query()
            ->where('project_id', $project->id)
            ->where('is_default', true)
            ->first();

        if ($existing) {
            return $existing;
        }

        return InventoryLocation::query()->create([
            'location_code' => $this->nextCode(),
            'name' => $project->project_name.' Store',
            'location_type' => InventoryLocation::PROJECT_STORE,
            'project_id' => $project->id,
            'is_active' => true,
            'is_default' => true,
            'is_system' => false,
        ]);
    }

    public function nextCode(): string
    {
        return DB::transaction(function () {
            $latest = InventoryLocation::query()
                ->where('location_code', 'like', 'LOC-%')
                ->orderByDesc('location_code')
                ->lockForUpdate()
                ->value('location_code');
            $sequence = 1;

            if (is_string($latest) && preg_match('/(\d+)$/', $latest, $matches) === 1) {
                $sequence = ((int) $matches[1]) + 1;
            }

            return sprintf('LOC-%06d', $sequence);
        });
    }
}
