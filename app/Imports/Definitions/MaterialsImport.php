<?php

namespace App\Imports\Definitions;

use App\Imports\AbstractImportDefinition;
use App\Imports\Support\ColumnDefinition;
use App\Imports\Support\DuplicateMode;
use App\Imports\Support\ImportContext;
use App\Imports\Support\RowOutcome;
use App\Models\InventoryLocation;
use App\Models\Material;
use App\Models\StockAdjustment;
use App\Services\MaterialUnitService;
use App\Services\StockAdjustmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaterialsImport extends AbstractImportDefinition
{
    public function key(): string
    {
        return 'materials';
    }

    public function label(): string
    {
        return 'Materials';
    }

    public function permission(): string
    {
        return 'inventory.import';
    }

    public function supportedModes(): array
    {
        return [DuplicateMode::CREATE_ONLY, DuplicateMode::SKIP_DUPLICATES, DuplicateMode::UPDATE_MATCHED, DuplicateMode::UPSERT];
    }

    public function columns(): array
    {
        return [
            new ColumnDefinition('material_code', 'Material Code', false, 'string', ['code'], 40, help: 'Required to update.'),
            new ColumnDefinition('name', 'Name', true, 'string', max: 255),
            new ColumnDefinition('category', 'Category', false, 'string', max: 100),
            new ColumnDefinition('description', 'Description', false, 'string', max: 2000),
            new ColumnDefinition('unit', 'Unit', true, 'string', ['uom'], 50),
            new ColumnDefinition('is_active', 'Active', false, 'boolean'),
            new ColumnDefinition('location', 'Location', false, 'string', ['location name', 'store', 'store name'], 255, help: 'Required when Opening Quantity is provided. Must already exist.'),
            new ColumnDefinition('opening_quantity', 'Opening Quantity', false, 'decimal', ['quantity', 'existing quantity'], help: 'Adds opening stock at the location. Does not replace existing balances.'),
            new ColumnDefinition('entry_date', 'Entry Date', false, 'date', ['date']),
            new ColumnDefinition('reference_number', 'Reference Number', false, 'string', ['reference'], 80),
            new ColumnDefinition('remarks', 'Remarks', false, 'string', max: 5000),
        ];
    }

    public function exampleRows(): array
    {
        return [[
            'material_code' => 'MAT-0001',
            'name' => 'Cement OPC 53',
            'category' => 'Cement',
            'unit' => 'Bag',
            'is_active' => 'yes',
            'location' => 'REPLACE_WITH_EXISTING_LOCATION',
            'opening_quantity' => 250,
            'entry_date' => now()->toDateString(),
            'reference_number' => 'OS-001',
            'remarks' => 'Example opening stock — replace location with a real store name',
        ]];
    }

    public function extraSheets(?\App\Models\User $user = null, ?\App\Models\Project $project = null): array
    {
        return app(StockAdjustmentsImport::class)->extraSheets($user, $project);
    }

    public function dropdowns(): array
    {
        return app(StockAdjustmentsImport::class)->dropdowns();
    }

    public function exampleRowsFor(?\App\Models\User $user = null, ?\App\Models\Project $project = null): array
    {
        $example = $this->exampleRows()[0];
        $stockExample = app(StockAdjustmentsImport::class)->exampleRowsFor($user, $project)[0] ?? [];
        $example['location'] = $stockExample['location'] ?? $example['location'];
        $example['material_code'] = $stockExample['material_code'] ?? $example['material_code'];
        $example['name'] = $stockExample['name'] ?? $example['name'];
        $example['unit'] = $stockExample['unit'] ?? $example['unit'];

        return [$example];
    }

    public function validateRow(int $rowNumber, array $raw, ImportContext $context): RowOutcome
    {
        $cast = $this->castRow($raw);
        if ($cast['errors'] !== []) {
            return new RowOutcome($rowNumber, RowOutcome::INVALID, $cast['data'], $cast['errors']);
        }

        $existing = null;
        if (! empty($cast['data']['material_code'])) {
            $existing = Material::query()->where('material_code', $cast['data']['material_code'])->first();
        }

        $data = $cast['data'];
        if (! empty($data['unit'])) {
            $data['unit'] = app(MaterialUnitService::class)->create((string) $data['unit'])->name;
        }
        $hasStock = $data['opening_quantity'] !== null && $data['opening_quantity'] !== '';

        if ($hasStock) {
            if ((float) $data['opening_quantity'] <= 0) {
                $cast['errors'][] = 'Opening Quantity must be greater than zero.';
            }
            if (empty($data['location']) && $context->project) {
                $cast['errors'][] = 'Location is required for a stock quantity entry.';
            }
            if ($context->mode === DuplicateMode::UPDATE_MATCHED) {
                $cast['errors'][] = 'Update matched mode changes item details only. Use a stock import to record quantities.';
            }
        }

        if ($hasStock && $cast['errors'] === []) {
            try {
                $location = app(StockAdjustmentService::class)->matchLocation(
                    $context->user,
                    $context->project,
                    (string) ($data['location'] ?: 'Central Store')
                );
                $data['_location_id'] = $location->id;
                $itemKey = strtolower((string) ($data['material_code'] ?: $data['name'] ?: ''));
                if ($itemKey !== '' && $context->seen('material-stock-rows', $itemKey.'@'.$location->id)) {
                    $cast['errors'][] = 'Duplicate row for this item and location in this file.';
                }
            } catch (ValidationException $e) {
                foreach ($e->errors() as $fieldErrors) {
                    foreach ($fieldErrors as $message) {
                        $cast['errors'][] = $message;
                    }
                }
            }
        }

        if ($cast['errors'] !== []) {
            return new RowOutcome($rowNumber, RowOutcome::INVALID, $data, $cast['errors']);
        }

        $decision = $this->decideAction($context, $existing?->id);
        if ($decision['error']) {
            return new RowOutcome($rowNumber, $decision['status'], $data, [$decision['error']], $data['material_code'] ?? $data['name'], $existing?->id);
        }

        return new RowOutcome($rowNumber, $decision['status'], $data, [], $data['material_code'] ?? $data['name'], $existing?->id, $decision['action']);
    }

    public function commit(RowOutcome $row, ImportContext $context): string
    {
        $data = $row->data;
        $data['is_active'] = $data['is_active'] ?? true;
        $data['created_by'] = $context->user->id;

        if ($row->action === 'update' && $row->existingId) {
            unset($data['material_code'], $data['created_by']);
            $itemFields = array_intersect_key($data, array_flip(['name', 'category', 'description', 'unit', 'is_active']));
            Material::query()->whereKey($row->existingId)->update(array_filter($itemFields, fn ($v) => $v !== null));
            $this->importOpening($row, $context, (int) $row->existingId);

            return 'updated';
        }

        return DB::transaction(function () use ($data, $row, $context) {
            if (empty($data['material_code'])) {
                $data['material_code'] = $this->nextCode();
            }
            $itemFields = array_intersect_key($data, array_flip(['material_code', 'name', 'category', 'description', 'unit', 'is_active', 'created_by']));
            $material = Material::query()->create($itemFields);
            $this->importOpening($row, $context, (int) $material->id);

            return 'created';
        });
    }

    private function importOpening(RowOutcome $row, ImportContext $context, int $materialId): void
    {
        $data = $row->data;
        if (($data['opening_quantity'] ?? null) === null || ($data['opening_quantity'] ?? '') === '' || empty($data['_location_id'])) {
            return;
        }

        $service = app(StockAdjustmentService::class);
        $location = InventoryLocation::query()->findOrFail($data['_location_id']);
        $date = $data['entry_date'] ?: now()->toDateString();
        $project = $location->isCentralStore() ? null : $context->project;

        if ($service->existingOpening($project?->id, $materialId, (int) $location->id)) {
            return;
        }

        $service->importPosted($context->user, $project, [
            'material_id' => $materialId,
            'location' => $location,
            'adjustment_type' => StockAdjustment::OPENING,
            'quantity' => $data['opening_quantity'],
            'adjustment_date' => $date,
            'reference_number' => $data['reference_number'] ?? null,
            'reason' => $data['remarks'] ?? 'Imported opening stock',
        ], $context->importBatchId);
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
}
