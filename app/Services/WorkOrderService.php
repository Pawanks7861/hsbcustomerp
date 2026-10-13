<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WorkOrderService
{
    public function __construct(
        private Access $access,
        private ActivityLogger $activity,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function catalog(Project $project, ?WorkOrder $except = null): array
    {
        $rows = [];

        foreach ($project->boqs()->with('revisions')->orderBy('id')->get() as $boq) {
            $revisionId = $boq->procurementRevisionId();

            if (! $revisionId) {
                continue;
            }

            $revisionNumber = $boq->revisions->firstWhere('id', $revisionId)?->revision_number;
            $items = BoqItem::query()
                ->where('boq_id', $boq->id)
                ->where('boq_revision_id', $revisionId)
                ->where('is_section', false)
                ->whereNotNull('quantity')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            foreach ($items as $item) {
                $rows[] = $this->catalogRow($boq, $item, $revisionNumber);
            }
        }

        return $this->withAllocation($rows, $this->exceptIds($except));
    }

    /**
     * @return array{boq_quantity: string, allocated_quantity: string, remaining_quantity: string}
     */
    public function summaryForItem(BoqItem $item): array
    {
        $allocated = $this->allocatedFor([(int) $item->id])[(int) $item->id] ?? '0.000';
        $boqQuantity = $this->qty($item->quantity);
        $remaining = bcsub($boqQuantity, $allocated, 3);

        return [
            'boq_quantity' => $boqQuantity,
            'allocated_quantity' => $allocated,
            'remaining_quantity' => $remaining,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historyForItem(BoqItem $item): array
    {
        return WorkOrderItem::query()
            ->with(['workOrder.vendor'])
            ->where('boq_item_id', $item->id)
            ->orderByDesc('id')
            ->get()
            ->map(function (WorkOrderItem $line): array {
                $order = $line->workOrder;

                return [
                    'id' => $line->id,
                    'work_order_id' => $order->id,
                    'work_order_number' => $order->work_order_number,
                    'contractor_name' => $order->contractor_name,
                    'quantity' => (string) $line->quantity,
                    'rate' => (string) $line->rate,
                    'amount' => (string) $line->amount,
                    'status' => $order->status,
                    'status_label' => $order->statusLabel(),
                    'work_status' => $order->work_status,
                    'work_label' => $order->workLabel(),
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(Project $project, User $user, array $data, ?WorkOrder $workOrder = null): WorkOrder
    {
        return DB::transaction(function () use ($project, $user, $data, $workOrder) {
            $requestedIds = collect($data['items'])->pluck('boq_item_id')->map(fn ($id) => (int) $id)->filter()->all();
            $this->lockBoqItems($requestedIds);
            $creating = $workOrder === null;

            if ($workOrder) {
                $workOrder = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->first();
                $this->assertStatus($workOrder, [WorkOrder::DRAFT], 'Only a draft work order can be edited.');
            }

            $vendor = $this->contractor((int) $data['vendor_id']);
            $lines = $this->resolveLines($project, $data['items'], $workOrder);
            $totals = $this->totals($lines, $data['gst_percentage'] ?? 0);

            if ($creating) {
                $workOrder = new WorkOrder([
                    'project_id' => $project->id,
                    'work_order_number' => DocumentNumber::next(WorkOrder::class, 'work_order_number', 'WO'),
                    'status' => WorkOrder::DRAFT,
                    'work_status' => WorkOrder::NOT_STARTED,
                    'created_by' => $user->id,
                ]);
            }

            $this->fill($workOrder, $vendor, $data, $totals, $lines);
            $workOrder->save();
            $this->replaceItems($workOrder, $lines);
            $this->activity->log($user->id, $creating ? 'work_order.created' : 'work_order.updated', $workOrder, [
                'work_order_number' => $workOrder->work_order_number,
                'grand_total' => (string) $workOrder->grand_total,
                'is_quantity_exceeded' => (bool) $workOrder->is_quantity_exceeded,
            ]);

            return $workOrder->load('items');
        });
    }

    public function submit(WorkOrder $workOrder, User $user): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $user) {
            $workOrder = $this->lockOrder($workOrder);
            $this->assertStatus($workOrder, [WorkOrder::DRAFT], 'Only a draft work order can be submitted.');
            $this->refreshAllocation($workOrder);
            $workOrder->status = WorkOrder::SUBMITTED;
            $workOrder->submitted_at = now();
            $workOrder->submitted_by = $user->id;
            $workOrder->save();
            $this->activity->log($user->id, 'work_order.submitted', $workOrder, [
                'is_quantity_exceeded' => (bool) $workOrder->is_quantity_exceeded,
            ]);

            return $workOrder->load('items');
        });
    }

    public function approve(WorkOrder $workOrder, User $user, ?string $remarks): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $user, $remarks) {
            $workOrder = $this->lockOrder($workOrder);
            $this->assertStatus($workOrder, [WorkOrder::SUBMITTED], 'Only a submitted work order can be approved.');
            $this->refreshAllocation($workOrder);
            $remarks = trim((string) $remarks);

            if ($workOrder->is_quantity_exceeded && $remarks === '') {
                throw ValidationException::withMessages([
                    'approval_remarks' => 'This work order exceeds the remaining BOQ quantity. Approval remarks are required.',
                ]);
            }

            $workOrder->status = WorkOrder::APPROVED;
            $workOrder->approved_at = now();
            $workOrder->approved_by = $user->id;
            $workOrder->approval_remarks = $remarks !== '' ? $remarks : null;
            $workOrder->rejected_at = null;
            $workOrder->rejected_by = null;
            $workOrder->rejection_reason = null;
            $workOrder->save();
            $this->activity->log($user->id, 'work_order.approved', $workOrder, [
                'remarks' => $workOrder->approval_remarks,
                'is_quantity_exceeded' => (bool) $workOrder->is_quantity_exceeded,
            ]);

            return $workOrder;
        });
    }

    public function reject(WorkOrder $workOrder, User $user, ?string $reason): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $user, $reason) {
            $workOrder = $this->lockOrder($workOrder);
            $this->assertStatus($workOrder, [WorkOrder::SUBMITTED], 'Only a submitted work order can be rejected.');
            $reason = trim((string) $reason);

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'rejection_reason' => 'A rejection reason is required.',
                ]);
            }

            $workOrder->status = WorkOrder::REJECTED;
            $workOrder->rejected_at = now();
            $workOrder->rejected_by = $user->id;
            $workOrder->rejection_reason = $reason;
            $workOrder->save();
            $this->activity->log($user->id, 'work_order.rejected', $workOrder, [
                'reason' => $reason,
            ]);

            return $workOrder;
        });
    }

    public function issue(WorkOrder $workOrder, User $user, ?string $remarks): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $user, $remarks) {
            $workOrder = $this->lockOrder($workOrder);
            $this->assertStatus($workOrder, [WorkOrder::APPROVED], 'Only an approved work order can be issued.');
            $this->refreshAllocation($workOrder);
            $remarks = trim((string) $remarks);

            if ($workOrder->is_quantity_exceeded && blank($workOrder->approval_remarks)) {
                if (! $this->access->allows($user, 'work_orders.approve')) {
                    abort(403, 'Excess BOQ quantity requires an authorized approval.');
                }

                if ($remarks === '') {
                    throw ValidationException::withMessages([
                        'approval_remarks' => 'This work order exceeds the remaining BOQ quantity. Approval remarks are required before it can be issued.',
                    ]);
                }

                $workOrder->approval_remarks = $remarks;
                $workOrder->approved_by = $workOrder->approved_by ?: $user->id;
                $workOrder->approved_at = $workOrder->approved_at ?: now();
            }

            $vendor = Vendor::query()->whereKey($workOrder->vendor_id)->first();

            if ($vendor) {
                $this->snapshotContractor($workOrder, $vendor);
            }

            if ($workOrder->amended_from_id) {
                $this->supersedeOriginal($workOrder, $user);
            }

            $workOrder->status = WorkOrder::ISSUED;
            $workOrder->work_status = WorkOrder::NOT_STARTED;
            $workOrder->issued_at = now();
            $workOrder->issued_by = $user->id;
            $workOrder->save();
            $this->activity->log($user->id, 'work_order.issued', $workOrder, [
                'contractor_name' => $workOrder->contractor_name,
                'is_quantity_exceeded' => (bool) $workOrder->is_quantity_exceeded,
            ]);

            return $workOrder;
        });
    }

    public function cancel(WorkOrder $workOrder, User $user, ?string $reason): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $user, $reason) {
            $workOrder = $this->lockOrder($workOrder);
            $this->assertStatus($workOrder, [
                WorkOrder::DRAFT,
                WorkOrder::SUBMITTED,
                WorkOrder::APPROVED,
                WorkOrder::ISSUED,
                WorkOrder::IN_PROGRESS,
                WorkOrder::COMPLETED,
                WorkOrder::REJECTED,
            ], 'This work order cannot be cancelled.');
            $reason = trim((string) $reason);

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'cancellation_reason' => 'A cancellation reason is required.',
                ]);
            }

            $workOrder->status = WorkOrder::CANCELLED;
            $workOrder->cancelled_at = now();
            $workOrder->cancelled_by = $user->id;
            $workOrder->cancellation_reason = $reason;
            $workOrder->save();
            $this->activity->log($user->id, 'work_order.cancelled', $workOrder, [
                'reason' => $reason,
            ]);

            return $workOrder;
        });
    }

    public function amend(WorkOrder $workOrder, User $user): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $user) {
            $workOrder = $this->lockOrder($workOrder);
            $this->assertStatus($workOrder, [WorkOrder::ISSUED, WorkOrder::IN_PROGRESS, WorkOrder::COMPLETED], 'Only an issued work order can be amended.');

            $open = WorkOrder::query()
                ->where('amended_from_id', $workOrder->id)
                ->whereIn('status', [WorkOrder::DRAFT, WorkOrder::SUBMITTED, WorkOrder::APPROVED])
                ->lockForUpdate()
                ->exists();

            if ($open) {
                throw ValidationException::withMessages([
                    'status' => 'An amendment for this work order is already open.',
                ]);
            }

            $copy = $workOrder->replicate([
                'work_order_number',
                'status',
                'work_status',
                'submitted_at',
                'submitted_by',
                'approved_at',
                'approved_by',
                'approval_remarks',
                'rejected_at',
                'rejected_by',
                'rejection_reason',
                'issued_at',
                'issued_by',
                'cancelled_at',
                'cancelled_by',
                'cancellation_reason',
            ]);
            $copy->amended_from_id = $workOrder->id;
            $copy->work_order_number = DocumentNumber::next(WorkOrder::class, 'work_order_number', 'WO');
            $copy->status = WorkOrder::DRAFT;
            $copy->work_status = WorkOrder::NOT_STARTED;
            $copy->created_by = $user->id;
            $copy->save();

            foreach ($workOrder->items as $item) {
                $line = $item->replicate(['executed_quantity', 'execution_status']);
                $line->work_order_id = $copy->id;
                $line->executed_quantity = 0;
                $line->execution_status = WorkOrder::NOT_STARTED;
                $line->save();
            }

            $this->activity->log($user->id, 'work_order.amended', $workOrder, [
                'amendment_id' => $copy->id,
                'amendment_number' => $copy->work_order_number,
            ]);
            $this->activity->log($user->id, 'work_order.created', $copy, [
                'amended_from' => $workOrder->work_order_number,
            ]);

            return $copy->load('items');
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function execute(WorkOrder $workOrder, User $user, array $lines, ?string $remarks): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $user, $lines, $remarks) {
            $workOrder = $this->lockOrder($workOrder);
            $this->assertStatus($workOrder, [WorkOrder::ISSUED, WorkOrder::IN_PROGRESS], 'Execution can be updated only after the work order is issued.');
            $workOrder->load('items');
            $byId = $workOrder->items->keyBy('id');
            $remarks = trim((string) $remarks);

            foreach (array_values($lines) as $index => $row) {
                $line = $byId->get((int) ($row['id'] ?? 0));

                if (! $line) {
                    throw ValidationException::withMessages([
                        "lines.$index.id" => 'This line does not belong to the work order.',
                    ]);
                }

                $executed = $this->qty($row['executed_quantity'] ?? 0);
                $status = (string) ($row['execution_status'] ?? WorkOrder::NOT_STARTED);

                if (! in_array($status, [WorkOrder::NOT_STARTED, WorkOrder::IN_PROGRESS, WorkOrder::COMPLETED], true)) {
                    throw ValidationException::withMessages([
                        "lines.$index.execution_status" => 'Select a valid execution status.',
                    ]);
                }

                if (bccomp($executed, '0', 3) < 0) {
                    throw ValidationException::withMessages([
                        "lines.$index.executed_quantity" => 'Executed quantity cannot be negative.',
                    ]);
                }

                if (bccomp($executed, $this->qty($line->quantity), 3) > 0) {
                    if (! $this->access->allows($user, 'work_orders.approve')) {
                        abort(403, 'Executed quantity exceeds the approved work order quantity.');
                    }

                    if ($remarks === '') {
                        throw ValidationException::withMessages([
                            'execution_remarks' => 'Approval remarks are required when executed quantity exceeds the work order quantity.',
                        ]);
                    }
                }

                $line->executed_quantity = $executed;
                $line->execution_status = $status;
                $line->save();
            }

            $workOrder->load('items');
            $this->applyExecutionStatus($workOrder);
            $workOrder->save();
            $this->activity->log($user->id, 'work_order.execution_updated', $workOrder, [
                'work_status' => $workOrder->work_status,
                'remarks' => $remarks !== '' ? $remarks : null,
            ]);

            return $workOrder;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(WorkOrder $workOrder): array
    {
        return ActivityLog::query()
            ->with('user:id,name')
            ->where('subject_type', WorkOrder::class)
            ->where('subject_id', $workOrder->id)
            ->orderBy('id')
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'user' => $log->user?->name,
                'properties' => $log->properties,
                'created_at' => $log->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @param  list<int>  $boqItemIds
     * @param  list<int>  $exceptWorkOrderIds
     * @return array<int, string>
     */
    public function allocatedFor(array $boqItemIds, array $exceptWorkOrderIds = []): array
    {
        $boqItemIds = array_values(array_unique(array_map('intval', $boqItemIds)));
        $result = [];

        foreach ($boqItemIds as $id) {
            $result[$id] = '0.000';
        }

        if ($boqItemIds === []) {
            return $result;
        }

        $replacedIds = WorkOrder::query()
            ->whereIn('status', WorkOrder::ALLOCATING)
            ->whereNotNull('amended_from_id')
            ->pluck('amended_from_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $skip = array_values(array_unique(array_merge(
            array_map('intval', $exceptWorkOrderIds),
            $replacedIds
        )));

        $sums = WorkOrderItem::query()
            ->selectRaw('boq_item_id, SUM(quantity) as allocated')
            ->whereIn('boq_item_id', $boqItemIds)
            ->whereHas('workOrder', function ($query) use ($skip): void {
                $query->whereIn('status', WorkOrder::ALLOCATING);

                if ($skip !== []) {
                    $query->whereNotIn('id', $skip);
                }
            })
            ->groupBy('boq_item_id')
            ->pluck('allocated', 'boq_item_id');

        foreach ($sums as $id => $allocated) {
            $result[(int) $id] = $this->qty($allocated);
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $exceptIds
     * @return list<array<string, mixed>>
     */
    private function withAllocation(array $rows, array $exceptIds): array
    {
        $allocated = $this->allocatedFor(array_column($rows, 'id'), $exceptIds);

        foreach ($rows as $index => $row) {
            $previous = $allocated[$row['id']] ?? '0.000';
            $rows[$index]['previously_allocated'] = $previous;
            $rows[$index]['remaining_quantity'] = bcsub($this->qty($row['quantity']), $previous, 3);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogRow(Boq $boq, BoqItem $item, mixed $revisionNumber): array
    {
        return [
            'id' => $item->id,
            'boq_id' => $boq->id,
            'boq_revision_id' => $item->boq_revision_id,
            'boq_number' => $boq->boq_number,
            'revision_number' => $revisionNumber,
            'item_number' => $item->item_number,
            'service_code' => $item->service_code,
            'description' => $item->description,
            'unit' => $item->unit,
            'quantity' => $this->qty($item->quantity),
            'rate' => Money::scale($item->rate, 2) ?? '0.00',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function resolveLines(Project $project, array $items, ?WorkOrder $workOrder): array
    {
        $requested = [];

        foreach (array_values($items) as $index => $row) {
            $id = (int) ($row['boq_item_id'] ?? 0);

            if ($id === 0) {
                throw ValidationException::withMessages([
                    "items.$index.boq_item_id" => 'Select a BOQ item.',
                ]);
            }

            if (isset($requested[$id])) {
                throw ValidationException::withMessages([
                    "items.$index.boq_item_id" => 'This BOQ item is already on the work order.',
                ]);
            }

            $requested[$id] = [
                'index' => $index,
                'quantity' => $this->qty($row['quantity'] ?? null),
                'rate' => Money::scale($row['rate'] ?? null, 2),
            ];

            if (bccomp($requested[$id]['quantity'], '0', 3) <= 0 || $requested[$id]['rate'] === null) {
                throw ValidationException::withMessages([
                    "items.$index.quantity" => 'Enter a quantity and rate for this line.',
                ]);
            }
        }

        if ($requested === []) {
            throw ValidationException::withMessages([
                'items' => 'Add at least one BOQ item.',
            ]);
        }

        $ids = array_keys($requested);
        sort($ids);
        $this->lockBoqItems($ids);
        $boqItems = BoqItem::query()->with(['boq', 'revision'])->whereIn('id', $ids)->get()->keyBy('id');
        $allowed = $this->allowedItemIds($project, $workOrder);
        $allocated = $this->allocatedFor($ids, $this->exceptIds($workOrder));
        $lines = [];

        foreach ($requested as $id => $row) {
            $item = $boqItems->get($id);
            $index = $row['index'];

            if (! $item || (int) $item->boq?->project_id !== (int) $project->id || ! in_array($id, $allowed, true) || $item->is_section) {
                throw ValidationException::withMessages([
                    "items.$index.boq_item_id" => 'Select a BOQ item from this project.',
                ]);
            }

            $boqQuantity = $this->qty($item->quantity);
            $previous = $allocated[$id] ?? '0.000';
            $after = bcsub(bcsub($boqQuantity, $previous, 3), $row['quantity'], 3);
            $exceeded = bccomp($after, '0', 3) < 0;
            $lines[] = [
                'boq_item_id' => $item->id,
                'boq_revision_id' => $item->boq_revision_id,
                'boq_number' => $item->boq?->boq_number,
                'revision_number' => $item->revision?->revision_number,
                'item_number' => $item->item_number,
                'service_code' => $item->service_code,
                'description' => $item->description,
                'unit' => $item->unit,
                'boq_quantity' => $boqQuantity,
                'previously_allocated' => $previous,
                'quantity' => $row['quantity'],
                'rate' => $row['rate'],
                'amount' => Money::itemAmount($row['quantity'], $row['rate']) ?? '0.00',
                'excess_quantity' => $exceeded ? bcsub('0', $after, 3) : '0.000',
                'is_exceeded' => $exceeded,
            ];
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{subtotal: string, gst_percentage: string, gst_amount: string, grand_total: string, is_quantity_exceeded: bool}
     */
    private function totals(array $lines, mixed $gstPercentage): array
    {
        $subtotal = '0.00';
        $exceeded = false;

        foreach ($lines as $line) {
            $subtotal = Money::add($subtotal, (string) $line['amount']);
            $exceeded = $exceeded || (bool) $line['is_exceeded'];
        }

        $percentage = Money::scale($gstPercentage ?? 0, 2) ?? '0.00';
        $gst = Money::percentage($subtotal, $percentage);

        return [
            'subtotal' => $subtotal,
            'gst_percentage' => $percentage,
            'gst_amount' => $gst,
            'grand_total' => Money::add($subtotal, $gst),
            'is_quantity_exceeded' => $exceeded,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{subtotal: string, gst_percentage: string, gst_amount: string, grand_total: string, is_quantity_exceeded: bool}  $totals
     * @param  list<array<string, mixed>>  $lines
     */
    private function fill(WorkOrder $workOrder, Vendor $vendor, array $data, array $totals, array $lines): void
    {
        $workOrder->vendor_id = $vendor->id;
        $workOrder->work_order_date = $data['work_order_date'];
        $workOrder->completion_date = $data['completion_date'] ?: null;
        $workOrder->scope = $this->nullableText($data['scope'] ?? null);
        $workOrder->payment_terms = $this->nullableText($data['payment_terms'] ?? null);
        $workOrder->terms_conditions = $this->nullableText($data['terms_conditions'] ?? null);
        $workOrder->remarks = $this->nullableText($data['remarks'] ?? null);
        $workOrder->subtotal = $totals['subtotal'];
        $workOrder->gst_percentage = $totals['gst_percentage'];
        $workOrder->gst_amount = $totals['gst_amount'];
        $workOrder->grand_total = $totals['grand_total'];
        $workOrder->is_quantity_exceeded = $totals['is_quantity_exceeded'];
        $this->snapshotContractor($workOrder, $vendor);
        unset($lines);
    }

    private function snapshotContractor(WorkOrder $workOrder, Vendor $vendor): void
    {
        $address = collect([$vendor->address, $vendor->city, $vendor->state, $vendor->pincode])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->implode(', ');

        $workOrder->contractor_code = $vendor->vendor_code;
        $workOrder->contractor_name = $vendor->name;
        $workOrder->contractor_contact = $vendor->contact_person;
        $workOrder->contractor_phone = $vendor->phone;
        $workOrder->contractor_email = $vendor->email;
        $workOrder->contractor_address = $address !== '' ? $address : null;
        $workOrder->contractor_gst = $vendor->gst_number;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceItems(WorkOrder $workOrder, array $lines): void
    {
        $existing = $workOrder->items()->get()->keyBy('boq_item_id');
        $kept = [];

        foreach (array_values($lines) as $index => $line) {
            $kept[] = $line['boq_item_id'];
            $current = $existing->get($line['boq_item_id']);
            $payload = $line + ['sort_order' => $index + 1];

            if ($current) {
                $current->fill($payload);
                $current->save();
                continue;
            }

            $workOrder->items()->create($payload + [
                'executed_quantity' => 0,
                'execution_status' => WorkOrder::NOT_STARTED,
            ]);
        }

        $workOrder->items()->whereNotIn('boq_item_id', $kept)->delete();
    }

    private function refreshAllocation(WorkOrder $workOrder): void
    {
        $workOrder->load('items');
        $ids = $workOrder->items->pluck('boq_item_id')->map(fn ($id) => (int) $id)->all();
        $this->lockBoqItems($ids);
        $items = BoqItem::query()->whereIn('id', $ids)->get()->keyBy('id');
        $allocated = $this->allocatedFor($ids, $this->exceptIds($workOrder));
        $exceeded = false;

        foreach ($workOrder->items as $line) {
            $boqQuantity = $this->qty($items->get($line->boq_item_id)?->quantity ?? $line->boq_quantity);
            $previous = $allocated[(int) $line->boq_item_id] ?? '0.000';
            $current = $this->qty($line->quantity);
            $after = bcsub(bcsub($boqQuantity, $previous, 3), $current, 3);
            $lineExceeded = bccomp($after, '0', 3) < 0;
            $line->boq_quantity = $boqQuantity;
            $line->previously_allocated = $previous;
            $line->excess_quantity = $lineExceeded ? bcsub('0', $after, 3) : '0.000';
            $line->is_exceeded = $lineExceeded;
            $line->save();
            $exceeded = $exceeded || $lineExceeded;
        }

        $workOrder->is_quantity_exceeded = $exceeded;
    }

    private function supersedeOriginal(WorkOrder $amendment, User $user): void
    {
        $original = WorkOrder::query()->whereKey($amendment->amended_from_id)->lockForUpdate()->first();

        if (! $original || ! in_array($original->status, WorkOrder::ALLOCATING, true)) {
            return;
        }

        $original->status = WorkOrder::SUPERSEDED;
        $original->save();
        $this->activity->log($user->id, 'work_order.superseded', $original, [
            'amendment_number' => $amendment->work_order_number,
        ]);
    }

    private function applyExecutionStatus(WorkOrder $workOrder): void
    {
        $anyProgress = false;
        $allComplete = $workOrder->items->isNotEmpty();

        foreach ($workOrder->items as $line) {
            if (bccomp($this->qty($line->executed_quantity), '0', 3) > 0 || $line->execution_status !== WorkOrder::NOT_STARTED) {
                $anyProgress = true;
            }

            if ($line->execution_status !== WorkOrder::COMPLETED) {
                $allComplete = false;
            }
        }

        if ($allComplete) {
            $workOrder->status = WorkOrder::COMPLETED;
            $workOrder->work_status = WorkOrder::COMPLETED;

            return;
        }

        if ($anyProgress) {
            $workOrder->status = WorkOrder::IN_PROGRESS;
            $workOrder->work_status = WorkOrder::IN_PROGRESS;

            return;
        }

        $workOrder->status = WorkOrder::ISSUED;
        $workOrder->work_status = WorkOrder::NOT_STARTED;
    }

    private function contractor(int $vendorId): Vendor
    {
        $vendor = Vendor::query()->whereKey($vendorId)->lockForUpdate()->first();

        if (! $vendor || ! $vendor->isActive() || ! $vendor->isContractor()) {
            throw ValidationException::withMessages([
                'vendor_id' => 'Select an active contractor.',
            ]);
        }

        return $vendor;
    }

    /**
     * @return list<int>
     */
    private function allowedItemIds(Project $project, ?WorkOrder $workOrder): array
    {
        $ids = array_column($this->catalog($project, $workOrder), 'id');

        if ($workOrder) {
            $ids = array_merge($ids, $workOrder->items()->pluck('boq_item_id')->map(fn ($id) => (int) $id)->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @return list<int>
     */
    private function exceptIds(?WorkOrder $workOrder): array
    {
        if (! $workOrder) {
            return [];
        }

        return array_values(array_filter([
            (int) $workOrder->id,
            $workOrder->amended_from_id ? (int) $workOrder->amended_from_id : null,
        ]));
    }

    /**
     * @param  list<int>  $ids
     */
    private function lockBoqItems(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        if ($ids === []) {
            return;
        }

        BoqItem::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
        $orderIds = WorkOrderItem::query()
            ->whereIn('boq_item_id', $ids)
            ->orderBy('work_order_id')
            ->pluck('work_order_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($orderIds !== []) {
            WorkOrder::query()->whereIn('id', $orderIds)->orderBy('id')->lockForUpdate()->get(['id']);
        }
    }

    private function lockOrder(WorkOrder $workOrder): WorkOrder
    {
        $ids = WorkOrderItem::query()
            ->where('work_order_id', $workOrder->id)
            ->pluck('boq_item_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $this->lockBoqItems($ids);
        $locked = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->first();

        if (! $locked) {
            throw new HttpException(404);
        }

        return $locked;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertStatus(WorkOrder $workOrder, array $allowed, string $message): void
    {
        if (! in_array($workOrder->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => $message,
            ]);
        }
    }

    private function qty(mixed $value): string
    {
        return Money::scale($value ?? 0, 3) ?? '0.000';
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
