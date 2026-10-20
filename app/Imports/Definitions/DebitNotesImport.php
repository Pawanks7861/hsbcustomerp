<?php

namespace App\Imports\Definitions;

use App\Imports\AbstractImportDefinition;
use App\Imports\Support\ColumnDefinition;
use App\Imports\Support\DuplicateMode;
use App\Imports\Support\ImportContext;
use App\Imports\Support\RowOutcome;
use App\Models\DebitNote;
use App\Models\Project;
use App\Models\Vendor;
use App\Services\Access;
use App\Services\DocumentNumber;
use App\Support\DocumentStatus;
use Illuminate\Support\Facades\DB;

class DebitNotesImport extends AbstractImportDefinition
{
    public function key(): string
    {
        return 'debit_notes';
    }

    public function label(): string
    {
        return 'Debit Notes (draft)';
    }

    public function permission(): string
    {
        return 'debit_notes.import';
    }

    public function supportedModes(): array
    {
        return [DuplicateMode::CREATE_ONLY];
    }

    public function columns(): array
    {
        return [
            new ColumnDefinition('project_code', 'Project Code', true, 'string', max: 50),
            new ColumnDefinition('vendor_code', 'Vendor Code', true, 'string', max: 40),
            new ColumnDefinition('date', 'Date', true, 'date'),
            new ColumnDefinition('reason', 'Reason', true, 'string', max: 2000),
            new ColumnDefinition('subtotal', 'Subtotal', true, 'decimal'),
            new ColumnDefinition('tax_amount', 'Tax Amount', false, 'decimal'),
            new ColumnDefinition('round_off', 'Round Off', false, 'decimal'),
            new ColumnDefinition('remarks', 'Remarks', false, 'string', max: 5000),
        ];
    }

    public function exampleRows(): array
    {
        return [[
            'project_code' => 'PRJ-001',
            'vendor_code' => 'V-1001',
            'date' => now()->toDateString(),
            'reason' => 'Short supply adjustment',
            'subtotal' => 1000,
            'tax_amount' => 180,
            'round_off' => 0,
        ]];
    }

    public function instructions(): string
    {
        return 'Creates DRAFT debit notes only. Approval and ledger impact follow the existing workflow. Does not auto-link invoices/POs.';
    }

    public function validateRow(int $rowNumber, array $raw, ImportContext $context): RowOutcome
    {
        return $this->validateNoteRow($rowNumber, $raw, $context);
    }

    public function commit(RowOutcome $row, ImportContext $context): string
    {
        return $this->commitNote($row, $context, DebitNote::class, 'DN');
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    protected function validateNoteRow(int $rowNumber, array $raw, ImportContext $context): RowOutcome
    {
        $cast = $this->castRow($raw);
        $data = $cast['data'];
        $errors = $cast['errors'];

        if (($data['subtotal'] ?? 0) <= 0) {
            $errors[] = 'Subtotal must be greater than zero.';
        }

        $project = Project::query()->where('project_code', $data['project_code'] ?? '')->first();
        if (! $project) {
            $errors[] = 'Project Code was not found.';
        } elseif (! app(Access::class)->seesAllProjects($context->user)
            && ! $context->user->projects()->where('projects.id', $project->id)->exists()) {
            $errors[] = 'You do not have access to this project.';
        } else {
            $data['project_id'] = $project->id;
        }

        $vendor = Vendor::query()->where('vendor_code', $data['vendor_code'] ?? '')->first();
        if (! $vendor) {
            $errors[] = 'Vendor Code was not found.';
        } else {
            $data['vendor_id'] = $vendor->id;
        }

        if ($errors !== []) {
            return new RowOutcome($rowNumber, RowOutcome::INVALID, $data, $errors);
        }

        $data['tax_amount'] = $data['tax_amount'] ?? 0;
        $data['round_off'] = $data['round_off'] ?? 0;
        $data['total_amount'] = round((float) $data['subtotal'] + (float) $data['tax_amount'] + (float) $data['round_off'], 2);

        return new RowOutcome($rowNumber, RowOutcome::VALID, $data, [], null, null, 'create');
    }

    /**
     * @param  class-string  $model
     */
    protected function commitNote(RowOutcome $row, ImportContext $context, string $model, string $prefix): string
    {
        DB::transaction(function () use ($row, $context, $model, $prefix) {
            $model::query()->create([
                'number' => DocumentNumber::next($model, 'number', $prefix),
                'date' => $row->data['date'],
                'project_id' => $row->data['project_id'],
                'vendor_id' => $row->data['vendor_id'],
                'reason' => $row->data['reason'],
                'remarks' => $row->data['remarks'],
                'status' => DocumentStatus::DRAFT,
                'subtotal' => $row->data['subtotal'],
                'tax_amount' => $row->data['tax_amount'],
                'round_off' => $row->data['round_off'],
                'total_amount' => $row->data['total_amount'],
                'created_by' => $context->user->id,
            ]);
        });

        return 'created';
    }
}
