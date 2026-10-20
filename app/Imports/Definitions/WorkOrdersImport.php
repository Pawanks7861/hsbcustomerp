<?php

namespace App\Imports\Definitions;

use App\Imports\AbstractImportDefinition;
use App\Imports\Support\ColumnDefinition;
use App\Imports\Support\DuplicateMode;
use App\Imports\Support\ImportContext;
use App\Imports\Support\RowOutcome;
use App\Models\Project;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\Access;
use App\Services\DocumentNumber;
use App\Support\DocumentStatus;
use Illuminate\Support\Facades\DB;

class WorkOrdersImport extends AbstractImportDefinition
{
    public function key(): string
    {
        return 'work_orders';
    }

    public function label(): string
    {
        return 'Work Orders (draft headers)';
    }

    public function permission(): string
    {
        return 'work_orders.import';
    }

    public function supportedModes(): array
    {
        return [DuplicateMode::CREATE_ONLY];
    }

    public function columns(): array
    {
        return [
            new ColumnDefinition('project_code', 'Project Code', true, 'string', max: 50),
            new ColumnDefinition('vendor_code', 'Contractor Code', true, 'string', ['contractor_code'], 40),
            new ColumnDefinition('scope', 'Scope', true, 'string', ['subject'], 5000),
            new ColumnDefinition('work_order_date', 'Work Order Date', false, 'date'),
            new ColumnDefinition('remarks', 'Remarks', false, 'string', max: 5000),
        ];
    }

    public function exampleRows(): array
    {
        return [[
            'project_code' => 'PRJ-001',
            'vendor_code' => 'V-1001',
            'scope' => 'Civil finishing package',
            'work_order_date' => now()->toDateString(),
        ]];
    }

    public function instructions(): string
    {
        return 'Creates DRAFT work-order headers only. Line items, issue, and execution stay in the normal Work Order screens. Contractor Code must reference an active contractor/both party.';
    }

    public function validateRow(int $rowNumber, array $raw, ImportContext $context): RowOutcome
    {
        $cast = $this->castRow($raw);
        $data = $cast['data'];
        $errors = $cast['errors'];

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
            $errors[] = 'Contractor Code was not found.';
        } elseif (! $vendor->isActive()) {
            $errors[] = 'Contractor is inactive.';
        } elseif (! $vendor->isContractor()) {
            $errors[] = 'Party must be contractor or both.';
        } else {
            $data['vendor_id'] = $vendor->id;
            $data['contractor_name'] = $vendor->name;
            $data['contractor_code'] = $vendor->vendor_code;
        }

        if ($errors !== []) {
            return new RowOutcome($rowNumber, RowOutcome::INVALID, $data, $errors);
        }

        return new RowOutcome($rowNumber, RowOutcome::VALID, $data, [], null, null, 'create');
    }

    public function commit(RowOutcome $row, ImportContext $context): string
    {
        DB::transaction(function () use ($row, $context) {
            WorkOrder::query()->create([
                'project_id' => $row->data['project_id'],
                'vendor_id' => $row->data['vendor_id'],
                'contractor_name' => $row->data['contractor_name'],
                'contractor_code' => $row->data['contractor_code'],
                'work_order_number' => DocumentNumber::next(WorkOrder::class, 'work_order_number', 'WO'),
                'scope' => $row->data['scope'],
                'work_order_date' => $row->data['work_order_date'] ?? now()->toDateString(),
                'remarks' => $row->data['remarks'],
                'status' => WorkOrder::DRAFT,
                'work_status' => WorkOrder::NOT_STARTED,
                'created_by' => $context->user->id,
                'subtotal' => 0,
                'gst_percentage' => 0,
                'gst_amount' => 0,
                'grand_total' => 0,
            ]);
        });

        return 'created';
    }
}
