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
use App\Services\StockAdjustmentService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockAdjustmentsImport extends AbstractImportDefinition
{
    public const MODE_OPENING = 'opening_stock';

    public const MODE_ADJUSTMENT = 'stock_adjustment';

    public const MODE_ITEM_ONLY = 'item_details_only';

    public function key(): string
    {
        return 'stock_adjustments';
    }

    public function label(): string
    {
        return 'Location Stock';
    }

    public function permission(): string
    {
        return 'inventory.import';
    }

    public function supportedModes(): array
    {
        return [
            self::MODE_OPENING,
            self::MODE_ADJUSTMENT,
            DuplicateMode::SKIP_DUPLICATES,
            self::MODE_ITEM_ONLY,
        ];
    }

    public function defaultMode(): string
    {
        return self::MODE_OPENING;
    }

    public function templateSheetTitle(): string
    {
        return 'Inventory Import';
    }

    public function columns(): array
    {
        return [
            new ColumnDefinition('material_code', 'Material Code', false, 'string', ['item code', 'code'], 40, help: 'Use the existing unique item code when the item already exists.'),
            new ColumnDefinition('name', 'Item Name', false, 'string', ['material name', 'name'], 255, help: 'Required to create a new item.'),
            new ColumnDefinition('description', 'Description', false, 'string', max: 2000),
            new ColumnDefinition('unit', 'Unit', false, 'string', ['uom'], 30, help: 'Required to create a new item. Must match the item master when the item exists.'),
            new ColumnDefinition('location', 'Location', false, 'string', ['location name', 'store', 'store name'], 255, help: 'Must match an existing location name or code. Required for stock entries.'),
            new ColumnDefinition('opening_quantity', 'Opening Quantity', false, 'decimal', ['quantity', 'existing quantity', 'stock quantity'], help: 'Required for opening stock and adjustments. Not a replacement balance.'),
            new ColumnDefinition('adjustment_type', 'Adjustment Type', false, 'string', ['stock operation', 'type'], 20, help: 'opening, increase, or decrease. Default is opening.'),
            new ColumnDefinition('entry_date', 'Entry Date', false, 'date', ['date', 'adjustment date']),
            new ColumnDefinition('reference_number', 'Reference Number', false, 'string', ['reference'], 80),
            new ColumnDefinition('remarks', 'Remarks', false, 'string', ['reason', 'notes'], 5000),
        ];
    }

    public function instructions(): string
    {
        return 'Locations must already exist in the ERP; this import never creates stores. Use Add Opening Stock to record an initial quantity, Stock Adjustment for a controlled increase or decrease, or Update Item Details Only to change the item master without touching stock. CSV files do not support location dropdowns — type a location name or code exactly. Repeating the same file will not add stock twice.';
    }

    public function exampleRows(): array
    {
        return [[
            'material_code' => 'MAT-0001',
            'name' => 'Cement OPC 53',
            'description' => 'Replace this example row',
            'unit' => 'Bag',
            'location' => 'REPLACE_WITH_EXISTING_LOCATION',
            'opening_quantity' => 250,
            'adjustment_type' => 'opening',
            'entry_date' => now()->toDateString(),
            'reference_number' => 'OS-001',
            'remarks' => 'Example — replace with real item and location values',
        ]];
    }

    public function exampleRowsFor(?User $user = null, ?Project $project = null): array
    {
        $example = $this->exampleRows()[0];
        $location = $this->authorizedLocations($user, $project)->first();
        $material = Material::query()->where('is_active', true)->orderBy('name')->first();

        if ($location) {
            $example['location'] = $location->name;
        }

        if ($material) {
            $example['material_code'] = $material->material_code;
            $example['name'] = $material->name;
            $example['unit'] = $material->unit;
            $example['description'] = $material->description ?: $example['description'];
        }

        if (! $location) {
            $example['location'] = 'CREATE_LOCATION_FIRST';
            $example['remarks'] = 'No locations exist yet. Create a location in Inventory → Locations, then download this template again.';
        }

        return [$example];
    }

    public function extraSheets(?User $user = null, ?Project $project = null): array
    {
        $locations = $this->authorizedLocations($user, $project);
        $rows = $locations->map(fn (InventoryLocation $location) => [
            $location->name,
            $location->location_code,
            $location->project?->project_name ?: ($project?->project_name ?? ''),
        ])->all();

        if ($rows === []) {
            $rows[] = [
                'Create a location in Inventory → Locations before importing stock',
                '',
                $project?->project_name ?? '',
            ];
        }

        return [[
            'title' => 'Available Locations',
            'headings' => ['Location Name', 'Location Code', 'Project'],
            'rows' => $rows,
            'widths' => [36, 18, 28],
        ]];
    }

    public function dropdowns(): array
    {
        return [[
            'column_key' => 'location',
            'sheet' => 'Available Locations',
            'range_column' => 'A',
        ]];
    }

    public function validateRow(int $rowNumber, array $raw, ImportContext $context): RowOutcome
    {
        $cast = $this->castRow($raw);
        $data = $cast['data'];
        $errors = $cast['errors'];

        if (! $context->project) {
            $errors[] = 'Select a project before importing location stock.';

            return new RowOutcome($rowNumber, RowOutcome::INVALID, $data, $errors);
        }

        $mode = $context->mode;
        $wantsStock = $mode !== self::MODE_ITEM_ONLY;
        $type = $this->normalizeType($data['adjustment_type'] ?? null, $mode);

        if ($type === null && $wantsStock) {
            $errors[] = 'Adjustment Type must be opening, increase, or decrease.';
        }

        if ($mode === self::MODE_ADJUSTMENT && $type === StockAdjustment::OPENING) {
            $errors[] = 'Stock Adjustment mode only supports increase or decrease. Use Add Opening Stock for an initial quantity.';
        }

        if ($wantsStock && ($data['opening_quantity'] === null || $data['opening_quantity'] === '')) {
            $errors[] = 'Opening Quantity is required for a stock entry.';
        } elseif ($wantsStock && (float) $data['opening_quantity'] <= 0) {
            $errors[] = 'Opening Quantity must be greater than zero.';
        }

        if ($wantsStock && empty($data['location'])) {
            $errors[] = 'Location is required for a stock quantity entry.';
        }

        $material = $this->findMaterial($data, $errors);
        $location = null;

        if ($wantsStock && ! empty($data['location']) && $errors === []) {
            try {
                $location = app(StockAdjustmentService::class)->matchLocation($context->user, $context->project, (string) $data['location']);
            } catch (ValidationException $e) {
                $errors = array_merge($errors, $this->flattenValidation($e));
            }
        }

        if ($material && ! empty($data['unit']) && strcasecmp((string) $material->unit, (string) $data['unit']) !== 0) {
            if ($mode !== self::MODE_ITEM_ONLY) {
                $errors[] = 'Unit does not match the item master ('.$material->unit.').';
            }
        }

        $itemKey = strtolower((string) ($data['material_code'] ?: $data['name'] ?: ''));
        if ($wantsStock && $itemKey !== '' && $location && $context->seen('stock-rows', $itemKey.'@'.$location->id.'@'.$type)) {
            $errors[] = 'Duplicate row for this item and location in this file.';
        }

        if ($errors !== []) {
            return new RowOutcome($rowNumber, RowOutcome::INVALID, $data, $errors, $data['material_code'] ?? $data['name'] ?? null, $material?->id);
        }

        if ($mode === self::MODE_ITEM_ONLY) {
            if (! $material && empty($data['name'])) {
                return new RowOutcome($rowNumber, RowOutcome::INVALID, $data, ['Item Name is required to create a material.'], $data['material_code'] ?? null);
            }
            if (! $material && empty($data['unit'])) {
                return new RowOutcome($rowNumber, RowOutcome::INVALID, $data, ['Unit is required to create a material.'], $data['name'] ?? $data['material_code'] ?? null);
            }

            $decision = $this->decideAction(
                new ImportContext($context->user, $material ? DuplicateMode::UPDATE_MATCHED : DuplicateMode::CREATE_ONLY, $context->project, $context->boqId, $context->importBatchId),
                $material?->id
            );

            return new RowOutcome($rowNumber, $decision['status'] === RowOutcome::DUPLICATE ? RowOutcome::VALID : $decision['status'], $data, [], $data['material_code'] ?? $data['name'], $material?->id, $material ? 'update' : 'create');
        }

        if (! $material && (empty($data['name']) || empty($data['unit']))) {
            return new RowOutcome($rowNumber, RowOutcome::INVALID, $data, ['Provide Material Code for an existing item, or Item Name and Unit to create one.'], $data['material_code'] ?? $data['name'] ?? null);
        }

        $data['_material_id'] = $material?->id;
        $data['_location_id'] = $location?->id;
        $data['_location_name'] = $location?->name;
        $data['_adjustment_type'] = $type;
        $data['_entry_date'] = $data['entry_date'] ?: now()->toDateString();

        if ($material && $location) {
            $service = app(StockAdjustmentService::class);
            $quantity = Money::scale((string) $data['opening_quantity'], 3) ?? '0.000';
            $fingerprint = $service->fingerprint(
                $context->project->id,
                $material->id,
                $location->id,
                $type,
                $quantity,
                $data['_entry_date'],
                $data['reference_number'] ?? null,
            );

            $openingExists = $type === StockAdjustment::OPENING
                ? $service->existingOpening($context->project->id, $material->id, $location->id)
                : null;
            $fingerprintExists = $service->existingFingerprint($fingerprint);

            if ($openingExists || $fingerprintExists) {
                $action = in_array($mode, [self::MODE_OPENING, DuplicateMode::SKIP_DUPLICATES, self::MODE_ADJUSTMENT], true)
                    ? 'skip'
                    : null;

                return new RowOutcome(
                    $rowNumber,
                    RowOutcome::DUPLICATE,
                    $data,
                    [],
                    ($material->material_code ?: $material->name).'@'.$location->name,
                    $material->id,
                    $action,
                );
            }
        }

        return new RowOutcome(
            $rowNumber,
            RowOutcome::VALID,
            $data,
            [],
            ($data['material_code'] ?? $data['name']).'@'.($location?->name ?? ''),
            $material?->id,
            'create',
        );
    }

    public function commit(RowOutcome $row, ImportContext $context): string
    {
        $data = $row->data;
        $project = $context->project;
        $service = app(StockAdjustmentService::class);

        if ($context->mode === self::MODE_ITEM_ONLY) {
            $this->upsertMaterial($data, $context, $row->existingId);

            return $row->existingId ? 'updated' : 'created';
        }

        $material = $row->existingId
            ? Material::query()->find($row->existingId)
            : $this->upsertMaterial($data, $context, null);

        $location = InventoryLocation::query()->findOrFail($data['_location_id']);

        $service->importPosted($context->user, $project, [
            'material' => $material,
            'location' => $location,
            'adjustment_type' => $data['_adjustment_type'],
            'quantity' => $data['opening_quantity'],
            'adjustment_date' => $data['_entry_date'],
            'reference_number' => $data['reference_number'] ?? null,
            'reason' => $data['remarks'] ?: null,
        ], $context->importBatchId);

        return 'created';
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

        if (empty($data['name'])) {
            return null;
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
    private function upsertMaterial(array $data, ImportContext $context, ?int $existingId): Material
    {
        if ($existingId) {
            $payload = array_filter([
                'name' => $data['name'] ?? null,
                'description' => $data['description'] ?? null,
                'unit' => $data['unit'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            if ($payload !== []) {
                Material::query()->whereKey($existingId)->update($payload);
            }

            return Material::query()->findOrFail($existingId);
        }

        return DB::transaction(function () use ($data, $context) {
            $code = $data['material_code'] ?: $this->nextCode();

            return Material::query()->create([
                'material_code' => $code,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'unit' => $data['unit'],
                'minimum_stock' => 0,
                'is_active' => true,
                'created_by' => $context->user->id,
            ]);
        });
    }

    private function nextCode(): string
    {
        $latest = Material::query()->orderByDesc('id')->lockForUpdate()->value('material_code');
        $sequence = 1;
        if (is_string($latest) && preg_match('/(\d+)$/', $latest, $m)) {
            $sequence = ((int) $m[1]) + 1;
        }

        return 'MAT-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function normalizeType(mixed $value, string $mode): ?string
    {
        $normalized = strtolower(trim((string) $value));

        if ($normalized === '') {
            return $mode === self::MODE_ADJUSTMENT ? null : StockAdjustment::OPENING;
        }

        return match ($normalized) {
            'opening', 'opening stock', 'add opening stock' => StockAdjustment::OPENING,
            'increase', 'stock increase', 'adjustment in' => StockAdjustment::INCREASE,
            'decrease', 'stock decrease', 'adjustment out' => StockAdjustment::DECREASE,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function flattenValidation(ValidationException $e): array
    {
        $messages = [];
        foreach ($e->errors() as $fieldErrors) {
            foreach ($fieldErrors as $message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * @return \Illuminate\Support\Collection<int, InventoryLocation>
     */
    private function authorizedLocations(?User $user, ?Project $project)
    {
        if (! $user || ! $project) {
            return collect();
        }

        return app(StockAdjustmentService::class)->selectableLocations($user, $project);
    }
}
