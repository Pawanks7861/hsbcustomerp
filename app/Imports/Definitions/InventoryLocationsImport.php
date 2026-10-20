<?php

namespace App\Imports\Definitions;

use App\Imports\AbstractImportDefinition;
use App\Imports\Support\ColumnDefinition;
use App\Imports\Support\DuplicateMode;
use App\Imports\Support\ImportContext;
use App\Imports\Support\RowOutcome;
use App\Models\InventoryLocation;
use App\Models\Project;
use App\Services\Access;

class InventoryLocationsImport extends AbstractImportDefinition
{
    public function key(): string
    {
        return 'inventory_locations';
    }

    public function label(): string
    {
        return 'Inventory Locations';
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
            new ColumnDefinition('location_code', 'Location Code', true, 'string', ['code'], 40),
            new ColumnDefinition('name', 'Name', true, 'string', max: 255),
            new ColumnDefinition('location_type', 'Location Type', true, 'enum', ['type'], allowed: InventoryLocation::TYPES),
            new ColumnDefinition('project_code', 'Project Code', false, 'string', max: 50, help: 'Required for project_store and site types.'),
            new ColumnDefinition('address', 'Address', false, 'string', max: 2000),
            new ColumnDefinition('is_active', 'Active', false, 'boolean'),
            new ColumnDefinition('is_default', 'Default', false, 'boolean'),
        ];
    }

    public function exampleRows(): array
    {
        return [[
            'location_code' => 'WH-MAIN',
            'name' => 'Main Warehouse',
            'location_type' => 'warehouse',
            'is_active' => 'yes',
            'is_default' => 'no',
        ]];
    }

    public function validateRow(int $rowNumber, array $raw, ImportContext $context): RowOutcome
    {
        $cast = $this->castRow($raw);
        $data = $cast['data'];
        $errors = $cast['errors'];

        $projectId = null;
        if (! empty($data['project_code'])) {
            $project = Project::query()->where('project_code', $data['project_code'])->first();
            if (! $project) {
                $errors[] = 'Project Code was not found.';
            } elseif (! app(Access::class)->seesAllProjects($context->user)
                && ! $context->user->projects()->where('projects.id', $project->id)->exists()) {
                $errors[] = 'You do not have access to this project.';
            } else {
                $projectId = $project->id;
            }
        }

        if (in_array($data['location_type'] ?? '', [InventoryLocation::PROJECT_STORE, InventoryLocation::SITE], true) && $projectId === null) {
            $errors[] = 'Project Code is required for project_store and site locations.';
        }

        $data['project_id'] = $projectId;

        if ($errors !== []) {
            return new RowOutcome($rowNumber, RowOutcome::INVALID, $data, $errors);
        }

        $existing = InventoryLocation::query()->where('location_code', $data['location_code'])->first();
        if ($existing?->is_system) {
            return new RowOutcome($rowNumber, RowOutcome::INVALID, $data, ['System locations cannot be changed by import.']);
        }

        $decision = $this->decideAction($context, $existing?->id);
        if ($decision['error']) {
            return new RowOutcome($rowNumber, $decision['status'], $data, [$decision['error']], $data['location_code'], $existing?->id);
        }

        return new RowOutcome($rowNumber, $decision['status'], $data, [], $data['location_code'], $existing?->id, $decision['action']);
    }

    public function commit(RowOutcome $row, ImportContext $context): string
    {
        $payload = [
            'location_code' => $row->data['location_code'],
            'name' => $row->data['name'],
            'location_type' => $row->data['location_type'],
            'project_id' => $row->data['project_id'],
            'address' => $row->data['address'],
            'is_active' => $row->data['is_active'] ?? true,
            'is_default' => $row->data['is_default'] ?? false,
            'is_system' => false,
        ];

        if ($row->action === 'update' && $row->existingId) {
            unset($payload['location_code'], $payload['is_system']);
            InventoryLocation::query()->whereKey($row->existingId)->update($payload);

            return 'updated';
        }

        InventoryLocation::query()->create($payload);

        return 'created';
    }
}
