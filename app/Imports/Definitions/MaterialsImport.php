<?php

namespace App\Imports\Definitions;

use App\Imports\AbstractImportDefinition;
use App\Imports\Support\ColumnDefinition;
use App\Imports\Support\DuplicateMode;
use App\Imports\Support\ImportContext;
use App\Imports\Support\RowOutcome;
use App\Models\Material;
use Illuminate\Support\Facades\DB;

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
            new ColumnDefinition('unit', 'Unit', true, 'string', ['uom'], 30),
            new ColumnDefinition('minimum_stock', 'Minimum Stock', false, 'decimal'),
            new ColumnDefinition('is_active', 'Active', false, 'boolean'),
        ];
    }

    public function exampleRows(): array
    {
        return [[
            'material_code' => 'MAT-0001',
            'name' => 'Cement OPC 53',
            'category' => 'Cement',
            'unit' => 'BAG',
            'minimum_stock' => 50,
            'is_active' => 'yes',
        ]];
    }

    public function validateRow(int $rowNumber, array $raw, ImportContext $context): RowOutcome
    {
        $cast = $this->castRow($raw);
        if ($cast['errors'] !== []) {
            return new RowOutcome($rowNumber, RowOutcome::INVALID, $cast['data'], $cast['errors']);
        }

        if (($cast['data']['minimum_stock'] ?? 0) < 0) {
            return new RowOutcome($rowNumber, RowOutcome::INVALID, $cast['data'], ['Minimum Stock cannot be negative.']);
        }

        $existing = null;
        if (! empty($cast['data']['material_code'])) {
            $existing = Material::query()->where('material_code', $cast['data']['material_code'])->first();
        }

        $decision = $this->decideAction($context, $existing?->id);
        if ($decision['error']) {
            return new RowOutcome($rowNumber, $decision['status'], $cast['data'], [$decision['error']], $cast['data']['material_code'] ?? $cast['data']['name'], $existing?->id);
        }

        return new RowOutcome($rowNumber, $decision['status'], $cast['data'], [], $cast['data']['material_code'] ?? $cast['data']['name'], $existing?->id, $decision['action']);
    }

    public function commit(RowOutcome $row, ImportContext $context): string
    {
        $data = $row->data;
        $data['minimum_stock'] = $data['minimum_stock'] ?? 0;
        $data['is_active'] = $data['is_active'] ?? true;
        $data['created_by'] = $context->user->id;

        if ($row->action === 'update' && $row->existingId) {
            unset($data['material_code'], $data['created_by']);
            Material::query()->whereKey($row->existingId)->update(array_filter($data, fn ($v) => $v !== null));

            return 'updated';
        }

        return DB::transaction(function () use ($data) {
            if (empty($data['material_code'])) {
                $data['material_code'] = $this->nextCode();
            }
            Material::query()->create($data);

            return 'created';
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
}
