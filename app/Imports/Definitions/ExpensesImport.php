<?php

namespace App\Imports\Definitions;

use App\Imports\AbstractImportDefinition;
use App\Imports\Support\ColumnDefinition;
use App\Imports\Support\DuplicateMode;
use App\Imports\Support\ImportContext;
use App\Imports\Support\RowOutcome;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Project;
use App\Models\Vendor;
use App\Services\Access;
use App\Services\DocumentNumber;
use App\Support\DocumentStatus;
use Illuminate\Support\Facades\DB;

class ExpensesImport extends AbstractImportDefinition
{
    public function key(): string
    {
        return 'expenses';
    }

    public function label(): string
    {
        return 'Expenses (draft)';
    }

    public function permission(): string
    {
        return 'expenses.import';
    }

    public function supportedModes(): array
    {
        return [DuplicateMode::CREATE_ONLY];
    }

    public function columns(): array
    {
        return [
            new ColumnDefinition('date', 'Date', true, 'date'),
            new ColumnDefinition('project_code', 'Project Code', false, 'string', max: 50),
            new ColumnDefinition('category_name', 'Category', true, 'string', ['category'], 255),
            new ColumnDefinition('description', 'Description', true, 'string', max: 2000),
            new ColumnDefinition('payee', 'Payee', false, 'string', max: 255),
            new ColumnDefinition('vendor_code', 'Vendor Code', false, 'string', max: 40),
            new ColumnDefinition('reference_number', 'Reference Number', false, 'string', max: 100),
            new ColumnDefinition('payment_mode', 'Payment Mode', false, 'string', max: 50),
            new ColumnDefinition('amount', 'Amount', true, 'decimal'),
            new ColumnDefinition('tax_amount', 'Tax Amount', false, 'decimal'),
            new ColumnDefinition('notes', 'Notes', false, 'string', max: 5000),
        ];
    }

    public function exampleRows(): array
    {
        return [[
            'date' => now()->toDateString(),
            'project_code' => 'PRJ-001',
            'category_name' => 'Site Utilities',
            'description' => 'Temporary power charges',
            'amount' => 1500,
            'tax_amount' => 0,
        ]];
    }

    public function instructions(): string
    {
        return 'Imports create DRAFT expenses only. Approval and payment stay in the normal workflow. Category must already exist.';
    }

    public function validateRow(int $rowNumber, array $raw, ImportContext $context): RowOutcome
    {
        $cast = $this->castRow($raw);
        $data = $cast['data'];
        $errors = $cast['errors'];

        if (($data['amount'] ?? 0) <= 0) {
            $errors[] = 'Amount must be greater than zero.';
        }

        $category = ExpenseCategory::query()->whereRaw('LOWER(name) = ?', [strtolower((string) ($data['category_name'] ?? ''))])->first();
        if (! $category) {
            $errors[] = 'Category was not found. Import categories first.';
        } else {
            $data['category_id'] = $category->id;
        }

        $data['project_id'] = null;
        if (! empty($data['project_code'])) {
            $project = Project::query()->where('project_code', $data['project_code'])->first();
            if (! $project) {
                $errors[] = 'Project Code was not found.';
            } elseif (! app(Access::class)->seesAllProjects($context->user)
                && ! $context->user->projects()->where('projects.id', $project->id)->exists()) {
                $errors[] = 'You do not have access to this project.';
            } else {
                $data['project_id'] = $project->id;
            }
        }

        $data['vendor_id'] = null;
        if (! empty($data['vendor_code'])) {
            $vendor = Vendor::query()->where('vendor_code', $data['vendor_code'])->first();
            if (! $vendor) {
                $errors[] = 'Vendor Code was not found.';
            } else {
                $data['vendor_id'] = $vendor->id;
            }
        }

        if ($errors !== []) {
            return new RowOutcome($rowNumber, RowOutcome::INVALID, $data, $errors);
        }

        return new RowOutcome($rowNumber, RowOutcome::VALID, $data, [], null, null, 'create');
    }

    public function commit(RowOutcome $row, ImportContext $context): string
    {
        $amount = (float) $row->data['amount'];
        $tax = (float) ($row->data['tax_amount'] ?? 0);

        DB::transaction(function () use ($row, $context, $amount, $tax) {
            Expense::query()->create([
                'number' => DocumentNumber::next(Expense::class, 'number', 'EXP'),
                'date' => $row->data['date'],
                'project_id' => $row->data['project_id'],
                'category_id' => $row->data['category_id'],
                'description' => $row->data['description'],
                'payee' => $row->data['payee'],
                'vendor_id' => $row->data['vendor_id'],
                'reference_number' => $row->data['reference_number'],
                'payment_mode' => $row->data['payment_mode'],
                'amount' => $amount,
                'tax_amount' => $tax,
                'round_off' => 0,
                'total_amount' => round($amount + $tax, 2),
                'payment_status' => Expense::PAYMENT_UNPAID,
                'status' => DocumentStatus::DRAFT,
                'notes' => $row->data['notes'],
                'created_by' => $context->user->id,
            ]);
        });

        return 'created';
    }
}
