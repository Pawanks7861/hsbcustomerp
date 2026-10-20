<?php

namespace App\Imports\Definitions;

use App\Imports\AbstractImportDefinition;
use App\Imports\Support\ColumnDefinition;
use App\Imports\Support\DuplicateMode;
use App\Imports\Support\ImportContext;
use App\Imports\Support\RowOutcome;
use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Services\Access;
use App\Services\DocumentNumber;
use App\Support\DocumentStatus;
use Illuminate\Support\Facades\DB;

class PurchaseRequestsImport extends AbstractImportDefinition
{
    public function key(): string
    {
        return 'purchase_requests';
    }

    public function label(): string
    {
        return 'Purchase Requests (draft)';
    }

    public function permission(): string
    {
        return 'purchase_requests.import';
    }

    public function supportedModes(): array
    {
        return [DuplicateMode::CREATE_ONLY];
    }

    public function columns(): array
    {
        return [
            new ColumnDefinition('project_code', 'Project Code', true, 'string', max: 50),
            new ColumnDefinition('boq_number', 'BOQ Number', false, 'string', max: 50),
            new ColumnDefinition('service_code', 'Service Code', false, 'string', max: 50),
            new ColumnDefinition('description', 'Item Description', true, 'string', max: 2000),
            new ColumnDefinition('unit', 'Unit', true, 'string', max: 30),
            new ColumnDefinition('quantity', 'Quantity', true, 'decimal'),
            new ColumnDefinition('request_date', 'Request Date', false, 'date'),
            new ColumnDefinition('required_date', 'Required Date', false, 'date'),
            new ColumnDefinition('remarks', 'Remarks', false, 'string', max: 2000),
        ];
    }

    public function exampleRows(): array
    {
        return [[
            'project_code' => 'PRJ-001',
            'description' => 'Cement bags for foundation',
            'unit' => 'BAG',
            'quantity' => 100,
            'request_date' => now()->toDateString(),
        ]];
    }

    public function instructions(): string
    {
        return 'Each row creates one DRAFT purchase request with a single line item. Rates are not imported (PR screens exclude rates). Submit/approve remains manual. Optional Service Code links a BOQ item when BOQ Number is provided.';
    }

    public function validateRow(int $rowNumber, array $raw, ImportContext $context): RowOutcome
    {
        $cast = $this->castRow($raw);
        $data = $cast['data'];
        $errors = $cast['errors'];

        if (($data['quantity'] ?? 0) <= 0) {
            $errors[] = 'Quantity must be greater than zero.';
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

        $data['boq_id'] = null;
        $data['boq_item_id'] = null;

        if ($project && ! empty($data['boq_number'])) {
            $boq = Boq::query()->where('project_id', $project->id)->where('boq_number', $data['boq_number'])->first();
            if (! $boq) {
                $errors[] = 'BOQ Number was not found on this project.';
            } else {
                $data['boq_id'] = $boq->id;
                if (! empty($data['service_code'])) {
                    $item = BoqItem::query()
                        ->where('boq_id', $boq->id)
                        ->where('service_code', $data['service_code'])
                        ->first();
                    if (! $item) {
                        $errors[] = 'Service Code was not found on the BOQ.';
                    } else {
                        $data['boq_item_id'] = $item->id;
                    }
                }
            }
        }

        if ($errors !== []) {
            return new RowOutcome($rowNumber, RowOutcome::INVALID, $data, $errors);
        }

        return new RowOutcome($rowNumber, RowOutcome::VALID, $data, [], null, null, 'create');
    }

    public function commit(RowOutcome $row, ImportContext $context): string
    {
        DB::transaction(function () use ($row, $context) {
            $pr = PurchaseRequest::query()->create([
                'project_id' => $row->data['project_id'],
                'boq_id' => $row->data['boq_id'],
                'boq_item_id' => $row->data['boq_item_id'],
                'pr_number' => DocumentNumber::next(PurchaseRequest::class, 'pr_number', 'PR'),
                'request_date' => $row->data['request_date'] ?? now()->toDateString(),
                'required_date' => $row->data['required_date'],
                'requested_by' => $context->user->id,
                'description' => $row->data['description'],
                'status' => DocumentStatus::DRAFT,
                'requested_boq_quantity' => $row->data['quantity'],
            ]);

            PurchaseRequestItem::query()->create([
                'purchase_request_id' => $pr->id,
                'boq_item_id' => $row->data['boq_item_id'],
                'description' => $row->data['description'],
                'service_code' => $row->data['service_code'],
                'unit' => $row->data['unit'],
                'quantity' => $row->data['quantity'],
                'remarks' => $row->data['remarks'],
            ]);
        });

        return 'created';
    }
}
