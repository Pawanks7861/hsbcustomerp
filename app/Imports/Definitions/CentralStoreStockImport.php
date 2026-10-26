<?php

namespace App\Imports\Definitions;

use App\Imports\AbstractImportDefinition;
use App\Imports\Support\ColumnDefinition;
use App\Imports\Support\DuplicateMode;
use App\Imports\Support\ImportContext;
use App\Imports\Support\RowOutcome;
use App\Models\InventoryLocation;
use App\Models\Material;
use App\Models\Project;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Services\InventoryLocationService;
use App\Services\MaterialUnitService;
use App\Services\StockAdjustmentService;
use App\Support\MaterialUnits;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CentralStoreStockImport extends AbstractImportDefinition
{
    public function key(): string
    {
        return 'central_store_stock';
    }

    public function label(): string
    {
        return 'Central Store Opening Stock';
    }

    public function permission(): string
    {
        return 'inventory.import';
    }

    public function supportedModes(): array
    {
        return [DuplicateMode::CREATE_ONLY, DuplicateMode::SKIP_DUPLICATES];
    }

    public function defaultMode(): string
    {
        return DuplicateMode::CREATE_ONLY;
    }

    public function templateSheetTitle(): string
    {
        return 'Central Store';
    }

    public function instructions(): string
    {
        return 'Import opening quantities into the Central Store. A project is not required. Repeating this file will skip materials that already have a posted opening balance. Unit must match the material master when the material already exists.';
    }

    public function columns(): array
    {
        return [
            new ColumnDefinition('material_code', 'Material Code', false, 'string', ['item code', 'code'], 40),
            new ColumnDefinition('name', 'Material Name', true, 'string', ['item name', 'name'], 255),
            new ColumnDefinition('category', 'Category', false, 'string', max: 100),
            new ColumnDefinition('unit', 'Unit', true, 'string', ['uom'], 50),
            new ColumnDefinition('description', 'Description', false, 'string', max: 2000),
            new ColumnDefinition('opening_quantity', 'Opening Quantity', true, 'decimal', ['quantity', 'opening qty']),
            new ColumnDefinition('store', 'Store', false, 'string', ['location', 'location name'], 255, help: 'Leave blank or enter Central Store.'),
            new ColumnDefinition('entry_date', 'Entry Date', false, 'date', ['date']),
            new ColumnDefinition('reference_number', 'Reference Number', false, 'string', ['reference'], 80),
            new ColumnDefinition('remarks', 'Remarks', false, 'string', max: 5000),
        ];
    }

    public function exampleRows(): array
    {
        return [[
            'material_code' => 'MAT-000001',
            'name' => 'Example material',
            'category' => 'Masonry',
            'unit' => 'Nos',
            'opening_quantity' => 5000,
            'store' => 'Central Store',
            'entry_date' => now()->toDateString(),
            'reference_number' => 'OS-CENTRAL-001',
            'remarks' => 'Example Central Store opening quantity',
        ]];
    }

    public function exampleRowsFor(?User $user = null, ?Project $project = null): array
    {
        $example = $this->exampleRows()[0];
        $store = app(InventoryLocationService::class)->ensureCentralStore();
        $example['store'] = $store->name;
        $material = Material::query()->where('is_active', true)->orderBy('name')->first();
        if ($material) {
            $example['material_code'] = $material->material_code;
            $example['name'] = $material->name;
            $example['unit'] = $material->unit;
            $example['category'] = $material->category ?: $example['category'];
        }

        return [$example];
    }

    public function extraSheets(?User $user = null, ?Project $project = null): array
    {
        $store = app(InventoryLocationService::class)->ensureCentralStore();

        return [[
            'title' => 'Available Locations',
            'headings' => ['Location Name', 'Location Code', 'Project'],
            'rows' => [[$store->name, $store->location_code, '']],
            'widths' => [36, 18, 28],
        ]];
    }

    public function validateRow(int $rowNumber, array $raw, ImportContext $context): RowOutcome
    {
        $cast = $this->castRow($raw);
        $data = $cast['data'];
        $errors = $cast['errors'];
        $store = app(InventoryLocationService::class)->ensureCentralStore();

        if (! empty($data['store']) && ! $this->matchesCentral((string) $data['store'], $store)) {
            $errors[] = 'Store must be the Central Store for this import.';
        }

        if ($data['opening_quantity'] === null || $data['opening_quantity'] === '') {
            $errors[] = 'Opening Quantity is required.';
        } elseif ((float) $data['opening_quantity'] <= 0) {
            $errors[] = 'Opening Quantity must be greater than zero.';
        }

        $unit = MaterialUnits::normalize((string) ($data['unit'] ?? ''));
        if ($unit === '') {
            $errors[] = 'Unit is required.';
        } else {
            $data['unit'] = $this->ensureUnit($unit);
        }

        $material = $this->findMaterial($data, $errors);
        if ($material && strcasecmp((string) $material->unit, (string) $data['unit']) !== 0) {
            $errors[] = 'Unit does not match the item master ('.$material->unit.').';
        }

        $itemKey = strtolower((string) ($data['material_code'] ?: $data['name'] ?: ''));
        if ($itemKey !== '' && $context->seen('central-stock-rows', $itemKey)) {
            $errors[] = 'Duplicate row for this item in this file.';
        }

        if ($errors !== []) {
            return new RowOutcome($rowNumber, RowOutcome::INVALID, $data, $errors, $data['material_code'] ?? $data['name'] ?? null, $material?->id);
        }

        $data['_location_id'] = $store->id;
        $data['_entry_date'] = $data['entry_date'] ?: now()->toDateString();
        $data['_material_id'] = $material?->id;

        if ($material) {
            $service = app(StockAdjustmentService::class);
            $quantity = Money::scale((string) $data['opening_quantity'], 3) ?? '0.000';
            $fingerprint = $service->fingerprint(null, $material->id, $store->id, StockAdjustment::OPENING, $quantity, $data['_entry_date'], $data['reference_number'] ?? null);

            if ($service->existingOpening(null, $material->id, $store->id) || $service->existingFingerprint($fingerprint)) {
                return new RowOutcome(
                    $rowNumber,
                    RowOutcome::DUPLICATE,
                    $data,
                    [],
                    ($material->material_code ?: $material->name).'@Central Store',
                    $material->id,
                    'skip',
                );
            }
        }

        return new RowOutcome($rowNumber, RowOutcome::VALID, $data, [], ($data['material_code'] ?? $data['name']).'@Central Store', $material?->id, 'create');
    }

    public function commit(RowOutcome $row, ImportContext $context): string
    {
        $data = $row->data;
        $store = InventoryLocation::query()->findOrFail($data['_location_id']);
        $material = $row->existingId
            ? Material::query()->findOrFail($row->existingId)
            : $this->createMaterial($data, $context);

        try {
            app(StockAdjustmentService::class)->importPosted($context->user, null, [
                'material' => $material,
                'location' => $store,
                'adjustment_type' => StockAdjustment::OPENING,
                'quantity' => $data['opening_quantity'],
                'adjustment_date' => $data['_entry_date'],
                'reference_number' => $data['reference_number'] ?? null,
                'reason' => $data['remarks'] ?: 'Imported Central Store opening stock',
            ], $context->importBatchId);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->implode(' ');
            if (str_contains(strtolower($messages), 'already')) {
                return 'skipped';
            }
            throw $e;
        }

        return $row->existingId ? 'updated' : 'created';
    }

    private function matchesCentral(string $value, InventoryLocation $store): bool
    {
        $needle = trim($value);

        return strcasecmp($needle, $store->name) === 0
            || strcasecmp($needle, $store->location_code) === 0
            || strcasecmp($needle, 'Central Store') === 0;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $errors
     */
    private function findMaterial(array $data, array &$errors): ?Material
    {
        if (! empty($data['material_code'])) {
            return Material::query()->where('material_code', $data['material_code'])->first();
        }

        $matches = Material::query()->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $data['name'])])->get();
        if ($matches->count() > 1) {
            $errors[] = 'Multiple items match this name. Provide the Material Code.';

            return null;
        }

        return $matches->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createMaterial(array $data, ImportContext $context): Material
    {
        return DB::transaction(function () use ($data, $context) {
            $latest = Material::query()->orderByDesc('id')->lockForUpdate()->value('material_code');
            $sequence = 1;
            if (is_string($latest) && preg_match('/(\d+)$/', $latest, $m)) {
                $sequence = ((int) $m[1]) + 1;
            }

            return Material::query()->create([
                'material_code' => $data['material_code'] ?: sprintf('MAT-%06d', $sequence),
                'name' => $data['name'],
                'category' => $data['category'] ?? null,
                'description' => $data['description'] ?? null,
                'unit' => $data['unit'],
                'is_active' => true,
                'created_by' => $context->user->id,
            ]);
        });
    }

    private function ensureUnit(string $unit): string
    {
        return app(MaterialUnitService::class)->create($unit)->name;
    }
}
