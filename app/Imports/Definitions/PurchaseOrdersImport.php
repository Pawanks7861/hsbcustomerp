<?php

namespace App\Imports\Definitions;

use App\Imports\AbstractImportDefinition;
use App\Imports\Support\ColumnDefinition;
use App\Imports\Support\DuplicateMode;
use App\Imports\Support\ImportContext;
use App\Imports\Support\RowOutcome;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\Vendor;
use App\Services\Access;
use App\Services\DocumentNumber;
use App\Support\DocumentStatus;
use Illuminate\Support\Facades\DB;

class PurchaseOrdersImport extends AbstractImportDefinition
{
    public function key(): string
    {
        return 'purchase_orders';
    }

    public function label(): string
    {
        return 'Purchase Orders (draft from approved PR)';
    }

    public function permission(): string
    {
        return 'purchase_orders.import';
    }

    public function supportedModes(): array
    {
        return [DuplicateMode::CREATE_ONLY];
    }

    public function columns(): array
    {
        return [
            new ColumnDefinition('project_code', 'Project Code', true, 'string', max: 50),
            new ColumnDefinition('pr_number', 'PR Number', true, 'string', max: 50),
            new ColumnDefinition('vendor_code', 'Vendor Code', true, 'string', max: 40),
            new ColumnDefinition('po_date', 'PO Date', false, 'date'),
            new ColumnDefinition('subject', 'Subject', false, 'string', max: 255),
            new ColumnDefinition('remarks', 'Remarks', false, 'string', max: 5000),
        ];
    }

    public function exampleRows(): array
    {
        return [[
            'project_code' => 'PRJ-001',
            'pr_number' => 'PR-2026-001',
            'vendor_code' => 'V-1001',
            'po_date' => now()->toDateString(),
            'subject' => 'Supply order',
        ]];
    }

    public function instructions(): string
    {
        return 'Each row creates one DRAFT purchase order by copying items from an APPROVED purchase request. Does not approve the PO or change rates on approved POs. PR must belong to the project and contain items.';
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

        $pr = null;
        if ($project && ! empty($data['pr_number'])) {
            $pr = PurchaseRequest::query()
                ->with('items')
                ->where('project_id', $project->id)
                ->where('pr_number', $data['pr_number'])
                ->first();

            if (! $pr) {
                $errors[] = 'PR Number was not found on this project.';
            } elseif ($pr->status !== DocumentStatus::APPROVED) {
                $errors[] = 'Purchase request must be approved before a PO can be imported.';
            } elseif ($pr->items->isEmpty()) {
                $errors[] = 'Approved purchase request has no items to copy.';
            } else {
                $data['purchase_request_id'] = $pr->id;
                $data['boq_id'] = $pr->boq_id;
                $data['boq_item_id'] = $pr->boq_item_id;
            }
        }

        $vendor = Vendor::query()->where('vendor_code', $data['vendor_code'] ?? '')->first();
        if (! $vendor) {
            $errors[] = 'Vendor Code was not found.';
        } elseif (! $vendor->isActive()) {
            $errors[] = 'Vendor is inactive.';
        } else {
            $data['vendor_id'] = $vendor->id;
        }

        if ($errors !== []) {
            return new RowOutcome($rowNumber, RowOutcome::INVALID, $data, $errors);
        }

        return new RowOutcome($rowNumber, RowOutcome::VALID, $data, [], $data['pr_number'], null, 'create');
    }

    public function commit(RowOutcome $row, ImportContext $context): string
    {
        $vendor = Vendor::query()->findOrFail($row->data['vendor_id']);
        $pr = PurchaseRequest::query()->with('items')->findOrFail($row->data['purchase_request_id']);

        DB::transaction(function () use ($row, $context, $vendor, $pr) {
            $lines = $pr->items->map(function ($item) {
                $qty = (float) $item->quantity;

                return [
                    'boq_item_id' => $item->boq_item_id,
                    'description' => $item->description,
                    'service_code' => $item->service_code,
                    'unit' => $item->unit,
                    'quantity' => $qty,
                    'rate' => 0,
                    'amount' => 0,
                    'discount_percentage' => 0,
                    'discount_amount' => 0,
                    'taxable_amount' => 0,
                    'cgst_amount' => 0,
                    'sgst_amount' => 0,
                    'line_total' => 0,
                ];
            })->all();

            $po = PurchaseOrder::query()->create([
                'project_id' => $row->data['project_id'],
                'boq_id' => $row->data['boq_id'],
                'boq_item_id' => $row->data['boq_item_id'],
                'purchase_request_id' => $pr->id,
                'vendor_id' => $vendor->id,
                'vendor_name_snapshot' => $vendor->name,
                'vendor_address_snapshot' => $vendor->address,
                'vendor_city_snapshot' => $vendor->city,
                'vendor_state_snapshot' => $vendor->state,
                'vendor_pincode_snapshot' => $vendor->pincode,
                'vendor_phone_snapshot' => $vendor->phone,
                'vendor_email_snapshot' => $vendor->email,
                'vendor_gst_snapshot' => $vendor->gst_number,
                'po_number' => DocumentNumber::next(PurchaseOrder::class, 'po_number', 'PO'),
                'subject' => $row->data['subject'],
                'po_date' => $row->data['po_date'] ?? now()->toDateString(),
                'supplier_name' => $vendor->name,
                'supplier_address' => $vendor->address,
                'supplier_phone' => $vendor->phone,
                'supplier_email' => $vendor->email,
                'gst_number' => $vendor->gst_number,
                'subtotal' => 0,
                'discount_total' => 0,
                'taxable_value' => 0,
                'gst_percentage' => 0,
                'gst_amount' => 0,
                'freight' => 0,
                'round_off' => 0,
                'grand_total' => 0,
                'remarks' => $row->data['remarks'],
                'status' => DocumentStatus::DRAFT,
                'created_by' => $context->user->id,
            ]);

            foreach ($lines as $line) {
                PurchaseOrderItem::query()->create([...$line, 'purchase_order_id' => $po->id]);
            }
        });

        return 'created';
    }
}
