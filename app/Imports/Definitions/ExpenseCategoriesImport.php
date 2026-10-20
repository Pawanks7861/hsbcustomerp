<?php

namespace App\Imports\Definitions;

use App\Imports\AbstractImportDefinition;
use App\Imports\Support\ColumnDefinition;
use App\Imports\Support\DuplicateMode;
use App\Imports\Support\ImportContext;
use App\Imports\Support\RowOutcome;
use App\Models\ExpenseCategory;

class ExpenseCategoriesImport extends AbstractImportDefinition
{
    public function key(): string
    {
        return 'expense_categories';
    }

    public function label(): string
    {
        return 'Expense Categories';
    }

    public function permission(): string
    {
        return 'expenses.import';
    }

    public function supportedModes(): array
    {
        return [DuplicateMode::CREATE_ONLY, DuplicateMode::SKIP_DUPLICATES, DuplicateMode::UPDATE_MATCHED, DuplicateMode::UPSERT];
    }

    public function columns(): array
    {
        return [
            new ColumnDefinition('name', 'Name', true, 'string', max: 255),
            new ColumnDefinition('is_active', 'Active', false, 'boolean'),
        ];
    }

    public function exampleRows(): array
    {
        return [['name' => 'Site Utilities', 'is_active' => 'yes']];
    }

    public function validateRow(int $rowNumber, array $raw, ImportContext $context): RowOutcome
    {
        $cast = $this->castRow($raw);
        if ($cast['errors'] !== []) {
            return new RowOutcome($rowNumber, RowOutcome::INVALID, $cast['data'], $cast['errors']);
        }

        $existing = ExpenseCategory::query()->whereRaw('LOWER(name) = ?', [strtolower($cast['data']['name'])])->first();
        $decision = $this->decideAction($context, $existing?->id);
        if ($decision['error']) {
            return new RowOutcome($rowNumber, $decision['status'], $cast['data'], [$decision['error']], $cast['data']['name'], $existing?->id);
        }

        return new RowOutcome($rowNumber, $decision['status'], $cast['data'], [], $cast['data']['name'], $existing?->id, $decision['action']);
    }

    public function commit(RowOutcome $row, ImportContext $context): string
    {
        $data = [
            'name' => $row->data['name'],
            'is_active' => $row->data['is_active'] ?? true,
        ];

        if ($row->action === 'update' && $row->existingId) {
            ExpenseCategory::query()->whereKey($row->existingId)->update($data);

            return 'updated';
        }

        ExpenseCategory::query()->create($data);

        return 'created';
    }
}
