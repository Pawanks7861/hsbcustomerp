<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\Material;
use App\Models\Project;
use App\Models\StockAdjustment;
use App\Models\StockLedgerEntry;
use App\Models\User;
use App\Support\MaterialAttributes;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockAdjustmentService
{
    public function __construct(
        private InventoryStock $stock,
        private InventoryLocationService $locations,
        private Access $access,
    ) {}

    /**
     * @return Collection<int, InventoryLocation>
     */
    public function selectableLocations(User $user, ?Project $project): Collection
    {
        $query = InventoryLocation::query()
            ->with('project')
            ->where('is_active', true)
            ->where('is_system', false)
            ->where('location_type', '!=', InventoryLocation::IN_TRANSIT);

        if ($project) {
            $query->where('project_id', $project->id);
        } else {
            $query->whereNull('project_id')->where('location_code', 'CENTRAL');
        }

        return $query
            ->orderBy('name')
            ->get()
            ->filter(function (InventoryLocation $location) use ($user) {
                try {
                    $this->access->abortUnlessCanAccessLocation($user, $location);
                } catch (\Throwable) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    /**
     * @return list<array{id: int, location_code: string, name: string, location_type: string, is_default: bool}>
     */
    public function locationCards(User $user, ?Project $project): array
    {
        return $this->selectableLocations($user, $project)
            ->map(fn (InventoryLocation $location) => [
                'id' => $location->id,
                'location_code' => $location->location_code,
                'name' => $location->name,
                'location_type' => $location->location_type,
                'is_default' => $location->is_default,
            ])
            ->all();
    }

    public function resolveLocation(User $user, ?Project $project, ?int $locationId): InventoryLocation
    {
        $location = $locationId
            ? InventoryLocation::query()->find($locationId)
            : ($project ? $this->locations->ensureProjectStore($project) : $this->locations->ensureCentralStore());

        if (! $location) {
            throw ValidationException::withMessages([
                'location_id' => $project
                    ? 'Choose a location that already exists in this project.'
                    : 'Choose the Central Store.',
            ]);
        }

        $this->assertUsableLocation($user, $project, $location);

        return $location;
    }

    public function matchLocation(User $user, ?Project $project, string $value): InventoryLocation
    {
        $needle = trim($value);

        if ($needle === '') {
            throw ValidationException::withMessages([
                'location' => 'Location is required.',
            ]);
        }

        $locations = $this->selectableLocations($user, $project);
        $byCode = $locations->first(fn (InventoryLocation $location) => strcasecmp((string) $location->location_code, $needle) === 0);

        if ($byCode) {
            return $byCode;
        }

        $byName = $locations->filter(fn (InventoryLocation $location) => strcasecmp($location->name, $needle) === 0);

        if ($byName->count() === 1) {
            return $byName->first();
        }

        if ($byName->count() > 1) {
            throw ValidationException::withMessages([
                'location' => 'Multiple locations match this name. Use the location code instead.',
            ]);
        }

        $existsElsewhere = InventoryLocation::query()
            ->where(function ($query) use ($needle) {
                $query->where('location_code', $needle)->orWhereRaw('LOWER(name) = ?', [mb_strtolower($needle)]);
            })
            ->where('location_type', '!=', InventoryLocation::IN_TRANSIT)
            ->exists();

        throw ValidationException::withMessages([
            'location' => $existsElsewhere
                ? ($project
                    ? 'This location is not available for the selected project.'
                    : 'This import only accepts the Central Store.')
                : 'Location was not found. Create it in Inventory → Locations before importing stock.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDraft(User $user, ?Project $project, array $data): StockAdjustment
    {
        $location = $this->resolveLocation($user, $project, isset($data['location_id']) ? (int) $data['location_id'] : null);
        $material = Material::query()->findOrFail($data['material_id']);
        $this->assertActiveMaterial($material);

        if (($data['adjustment_type'] ?? '') === StockAdjustment::OPENING
            && $this->existingOpening($project?->id, $material->id, $location->id)) {
            throw ValidationException::withMessages([
                'quantity' => 'Opening stock already exists for this item at this location. Use an increase or decrease to correct it.',
            ]);
        }

        $payload = [
            'project_id' => $project?->id,
            'material_id' => $material->id,
            'location_id' => $location->id,
            'adjustment_date' => $data['adjustment_date'],
            'adjustment_type' => $data['adjustment_type'],
            'quantity' => Money::scale($data['quantity'], 3),
            'unit_cost' => MaterialAttributes::normalizeUnitCost($data['unit_cost'] ?? null),
            'reference_number' => $data['reference_number'] ?? null,
            'reason' => $data['reason'] ?? $this->defaultReason($data['adjustment_type']),
            'attachment_path' => $data['attachment_path'] ?? null,
            'status' => StockAdjustment::DRAFT,
            'created_by' => $user->id,
        ];

        return $this->insertWithNumber($payload);
    }

    public function post(User $user, ?Project $project, StockAdjustment $adjustment): void
    {
        DB::transaction(function () use ($user, $project, $adjustment): void {
            $locked = StockAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) ($locked->project_id ?? 0) === (int) ($project?->id ?? 0), 404);
            abort_unless($locked->status === StockAdjustment::DRAFT, 403);

            $location = $this->resolveLocation($user, $project, $locked->location_id ? (int) $locked->location_id : null);

            if ($locked->location_id === null) {
                $locked->location_id = $location->id;
            }

            if ($locked->adjustment_type === StockAdjustment::OPENING
                && $this->existingOpening($project?->id, (int) $locked->material_id, (int) $location->id)) {
                throw ValidationException::withMessages([
                    'quantity' => 'Opening stock already exists for this item at this location. Use an increase or decrease to correct it.',
                ]);
            }

            $this->postLocked($user, $project, $locked, $location);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function importPosted(User $user, ?Project $project, array $data, ?int $importBatchId = null): StockAdjustment
    {
        $location = $data['location'] instanceof InventoryLocation
            ? $data['location']
            : $this->resolveLocation($user, $project, isset($data['location_id']) ? (int) $data['location_id'] : null);
        $this->assertUsableLocation($user, $project, $location);

        $material = $data['material'] instanceof Material
            ? $data['material']
            : Material::query()->findOrFail($data['material_id']);
        $this->assertActiveMaterial($material);

        $type = $data['adjustment_type'];
        $quantity = Money::scale($data['quantity'], 3) ?? '0.000';
        $date = $data['adjustment_date'];
        $reference = $data['reference_number'] ?? null;
        $fingerprint = $this->fingerprint($project?->id, $material->id, $location->id, $type, $quantity, $date, $reference);

        if ($type === StockAdjustment::OPENING && $this->existingOpening($project?->id, $material->id, $location->id)) {
            throw ValidationException::withMessages([
                'quantity' => 'Opening stock already exists for this item at this location.',
            ]);
        }

        if ($this->existingFingerprint($fingerprint)) {
            throw ValidationException::withMessages([
                'quantity' => 'This stock entry was already imported.',
            ]);
        }

        $adjustment = $this->insertWithNumber([
            'project_id' => $project?->id,
            'material_id' => $material->id,
            'location_id' => $location->id,
            'adjustment_date' => $date,
            'adjustment_type' => $type,
            'quantity' => $quantity,
            'unit_cost' => MaterialAttributes::normalizeUnitCost($data['unit_cost'] ?? null),
            'reference_number' => $reference,
            'reason' => $data['reason'] ?? $this->defaultReason($type),
            'import_fingerprint' => $fingerprint,
            'import_batch_id' => $importBatchId,
            'status' => StockAdjustment::DRAFT,
            'created_by' => $user->id,
        ]);

        $this->postLocked($user, $project, $adjustment, $location);

        return $adjustment->fresh();
    }

    public function existingOpening(?int $projectId, int $materialId, int $locationId): ?StockAdjustment
    {
        return StockAdjustment::query()
            ->when($projectId === null, fn ($query) => $query->whereNull('project_id'), fn ($query) => $query->where('project_id', $projectId))
            ->where('material_id', $materialId)
            ->where('location_id', $locationId)
            ->where('adjustment_type', StockAdjustment::OPENING)
            ->where('status', StockAdjustment::POSTED)
            ->first();
    }

    public function existingFingerprint(string $fingerprint): ?StockAdjustment
    {
        return StockAdjustment::query()
            ->where('import_fingerprint', $fingerprint)
            ->where('status', StockAdjustment::POSTED)
            ->first();
    }

    public function fingerprint(
        ?int $projectId,
        int $materialId,
        int $locationId,
        string $type,
        string $quantity,
        string $date,
        ?string $reference,
    ): string {
        return hash('sha256', implode('|', [
            $projectId === null ? 'central' : (string) $projectId,
            $materialId,
            $locationId,
            $type,
            Money::scale($quantity, 3) ?? '0.000',
            $date,
            strtolower(trim((string) $reference)),
        ]));
    }

    private function postLocked(User $user, ?Project $project, StockAdjustment $locked, InventoryLocation $location): void
    {
        $quantity = Money::scale($locked->quantity, 3) ?? '0.000';
        $in = $locked->increasesStock();
        $unitCost = $locked->unit_cost !== null && $locked->unit_cost !== ''
            ? Money::scale((string) $locked->unit_cost, 2)
            : null;

        $this->stock->move(
            $project?->id,
            (int) $locked->material_id,
            $locked->adjustment_date->toDateString(),
            $in ? StockLedgerEntry::ADJUSTMENT_IN : StockLedgerEntry::ADJUSTMENT_OUT,
            'stock_adjustment',
            (int) $locked->id,
            (int) $locked->id,
            $locked->adjustment_number,
            $in ? $quantity : '0',
            $in ? '0' : $quantity,
            $user->id,
            $locked->reason,
            (int) $location->id,
            $in ? $unitCost : null,
            $in && $unitCost === null,
        );

        $locked->update([
            'location_id' => $location->id,
            'status' => StockAdjustment::POSTED,
            'posted_at' => now(),
            'posted_by' => $user->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function insertWithNumber(array $payload): StockAdjustment
    {
        $exception = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($payload) {
                    $payload['adjustment_number'] = DocumentNumber::next(StockAdjustment::class, 'adjustment_number', 'ADJ');

                    return StockAdjustment::query()->create($payload);
                });
            } catch (UniqueConstraintViolationException $caught) {
                $exception = $caught;
            }
        }

        throw $exception;
    }

    private function assertUsableLocation(User $user, ?Project $project, InventoryLocation $location): void
    {
        if ($project === null) {
            if (! $location->isCentralStore()) {
                throw ValidationException::withMessages([
                    'location_id' => 'Central Store stock can only be recorded against the Central Store.',
                ]);
            }
        } elseif ($location->isCentralStore() || (int) $location->project_id !== (int) $project->id) {
            throw ValidationException::withMessages([
                'location_id' => 'Choose a location that belongs to this project.',
            ]);
        }

        if ($location->isInTransit() || $location->is_system) {
            throw ValidationException::withMessages([
                'location_id' => 'Stock cannot be recorded against a system or in-transit location.',
            ]);
        }

        if (! $location->is_active) {
            throw ValidationException::withMessages([
                'location_id' => 'This location is inactive.',
            ]);
        }

        try {
            $this->access->abortUnlessCanAccessLocation($user, $location);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'location_id' => 'You do not have access to this location.',
            ]);
        }
    }

    private function assertActiveMaterial(Material $material): void
    {
        if (! $material->is_active) {
            throw ValidationException::withMessages([
                'material_id' => 'Choose an active inventory item.',
            ]);
        }
    }

    private function defaultReason(string $type): string
    {
        return match ($type) {
            StockAdjustment::OPENING => 'Opening stock',
            StockAdjustment::INCREASE => 'Stock increase',
            StockAdjustment::DECREASE => 'Stock decrease',
            default => 'Stock adjustment',
        };
    }
}
