<?php

namespace App\Imports\Definitions;

use App\Imports\AbstractImportDefinition;
use App\Imports\Support\ColumnDefinition;
use App\Imports\Support\DuplicateMode;
use App\Imports\Support\ImportContext;
use App\Imports\Support\RowOutcome;
use App\Models\Project;

class ProjectsImport extends AbstractImportDefinition
{
    public function key(): string
    {
        return 'projects';
    }

    public function label(): string
    {
        return 'Projects';
    }

    public function permission(): string
    {
        return 'projects.import';
    }

    public function supportedModes(): array
    {
        return [DuplicateMode::CREATE_ONLY, DuplicateMode::SKIP_DUPLICATES, DuplicateMode::UPDATE_MATCHED, DuplicateMode::UPSERT];
    }

    public function columns(): array
    {
        return [
            new ColumnDefinition('project_code', 'Project Code', true, 'string', ['code'], 50),
            new ColumnDefinition('project_name', 'Project Name', true, 'string', ['name'], 255),
            new ColumnDefinition('description', 'Description', false, 'string', max: 5000),
        ];
    }

    public function exampleRows(): array
    {
        return [[
            'project_code' => 'PRJ-001',
            'project_name' => 'Sample Tower',
            'description' => 'Example project',
        ]];
    }

    public function validateRow(int $rowNumber, array $raw, ImportContext $context): RowOutcome
    {
        $cast = $this->castRow($raw);
        if ($cast['errors'] !== []) {
            return new RowOutcome($rowNumber, RowOutcome::INVALID, $cast['data'], $cast['errors']);
        }

        $existing = Project::query()->where('project_code', $cast['data']['project_code'])->first();
        $decision = $this->decideAction($context, $existing?->id);

        if ($decision['error']) {
            return new RowOutcome($rowNumber, $decision['status'], $cast['data'], [$decision['error']], $cast['data']['project_code'], $existing?->id);
        }

        return new RowOutcome($rowNumber, $decision['status'], $cast['data'], [], $cast['data']['project_code'], $existing?->id, $decision['action']);
    }

    public function commit(RowOutcome $row, ImportContext $context): string
    {
        if ($row->action === 'update' && $row->existingId) {
            Project::query()->whereKey($row->existingId)->update([
                'project_name' => $row->data['project_name'],
                'description' => $row->data['description'],
            ]);

            return 'updated';
        }

        Project::query()->create($row->data);

        return 'created';
    }
}
