<?php

namespace App\Services;

use App\Models\InternalDeliveryNote;
use App\Models\MaterialIssue;
use App\Models\MaterialReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\DocumentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ApprovalInbox
{
    public function __construct(private Access $access) {}

    /**
     * Document types that wait on an approver. The first permission is the one
     * that decides whether a user sees the document in their inbox.
     *
     * @return array<string, array{label: string, action: string, description: string, permissions: list<string>}>
     */
    public static function types(): array
    {
        return [
            'purchase_requests' => [
                'label' => 'Purchase Requests',
                'action' => 'Approve / reject',
                'description' => 'Submitted purchase requests waiting for approval.',
                'permissions' => ['purchase_requests.approve', 'purchase_requests.reject'],
            ],
            'purchase_orders' => [
                'label' => 'Purchase Orders',
                'action' => 'Approve / reject',
                'description' => 'Submitted purchase orders waiting for approval.',
                'permissions' => ['purchase_orders.approve', 'purchase_orders.reject'],
            ],
            'work_orders' => [
                'label' => 'Work Orders',
                'action' => 'Approve / reject',
                'description' => 'Submitted work orders waiting for approval.',
                'permissions' => ['work_orders.approve', 'work_orders.reject'],
            ],
            'grns' => [
                'label' => 'Goods Receipt Notes',
                'action' => 'Post to stock / reject',
                'description' => 'Submitted GRNs waiting to be posted to stock.',
                'permissions' => ['grn.post', 'grn.reject'],
            ],
            'material_issues' => [
                'label' => 'Material Issues',
                'action' => 'Post / cancel',
                'description' => 'Draft material issues waiting to be posted out of stock.',
                'permissions' => ['inventory.post'],
            ],
            'stock_adjustments' => [
                'label' => 'Stock Adjustments',
                'action' => 'Create / post',
                'description' => 'Draft stock adjustments waiting to be posted.',
                'permissions' => ['inventory.adjust'],
            ],
            'internal_delivery_notes' => [
                'label' => 'Internal Delivery Notes',
                'action' => 'Approve / reject',
                'description' => 'Submitted stock transfers waiting for approval.',
                'permissions' => ['idn.approve', 'idn.reject'],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function typesFor(User $user): array
    {
        return array_values(array_filter(
            array_keys(self::types()),
            fn (string $type) => $this->access->allows($user, self::types()[$type]['permissions'][0])
        ));
    }

    public function count(User $user): int
    {
        $projects = $this->projectIds($user);

        return array_sum(array_map(
            fn (string $type) => $this->query($type, $projects)->count(),
            $this->typesFor($user)
        ));
    }

    /**
     * @return array<string, int>
     */
    public function counts(User $user): array
    {
        $projects = $this->projectIds($user);
        $counts = [];

        foreach ($this->typesFor($user) as $type) {
            $counts[$type] = $this->query($type, $projects)->count();
        }

        return $counts;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function items(User $user, ?string $only = null): Collection
    {
        $projects = $this->projectIds($user);
        $rows = collect();

        foreach ($this->typesFor($user) as $type) {
            if ($only !== null && $only !== $type) {
                continue;
            }

            $records = $this->query($type, $projects)->with($this->relations($type))->limit(200)->get();
            $rows = $rows->concat($records->map(fn ($record) => $this->row($type, $record)));
        }

        return $rows->sortBy('waiting_since')->values();
    }

    /**
     * @param  list<int>|null  $projects
     */
    private function query(string $type, ?array $projects): Builder
    {
        $scoped = fn (Builder $query) => $projects === null ? $query : $query->whereIn('project_id', $projects);

        return match ($type) {
            'purchase_requests' => $scoped(PurchaseRequest::query()->where('status', DocumentStatus::SUBMITTED)),
            'purchase_orders' => $scoped(PurchaseOrder::query()->where('status', DocumentStatus::SUBMITTED)),
            'work_orders' => $scoped(WorkOrder::query()->where('status', WorkOrder::SUBMITTED)),
            'grns' => $scoped(MaterialReceipt::query()
                ->where('document_kind', MaterialReceipt::KIND_GRN)
                ->where('status', MaterialReceipt::STATUS_SUBMITTED)),
            'material_issues' => $scoped(MaterialIssue::query()->where('status', MaterialIssue::DRAFT)),
            'stock_adjustments' => $scoped(StockAdjustment::query()->where('status', StockAdjustment::DRAFT)),
            'internal_delivery_notes' => InternalDeliveryNote::query()
                ->where('status', InternalDeliveryNote::SUBMITTED)
                ->when($projects !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                    ->whereIn('source_project_id', $projects)
                    ->orWhereIn('destination_project_id', $projects))),
        };
    }

    /**
     * @return list<string>
     */
    private function relations(string $type): array
    {
        return match ($type) {
            'purchase_requests' => ['project', 'requestedBy', 'items'],
            'purchase_orders' => ['project', 'createdBy'],
            'work_orders' => ['project', 'createdBy'],
            'grns' => ['project', 'purchaseOrder', 'submitter'],
            'material_issues' => ['project', 'creator', 'items.material'],
            'stock_adjustments' => ['project', 'material', 'creator'],
            'internal_delivery_notes' => ['sourceLocation', 'destinationLocation', 'requester'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $type, mixed $record): array
    {
        $project = $record->project ?? null;
        $base = [
            'key' => $type.'-'.$record->id,
            'type' => $type,
            'type_label' => self::types()[$type]['label'],
            'project' => $project ? ['id' => $project->id, 'code' => $project->project_code, 'name' => $project->project_name] : null,
        ];

        return [...$base, ...match ($type) {
            'purchase_requests' => [
                'number' => $record->pr_number,
                'date' => $record->request_date?->toDateString(),
                'summary' => $record->description ?: $record->items->count().' item(s)',
                'amount' => (string) $record->items->sum(fn ($item) => (float) $item->estimated_amount),
                'requested_by' => $record->requestedBy?->name,
                'flag' => $record->is_boq_quantity_exceeded ? 'Exceeds BOQ quantity' : null,
                'waiting_since' => $this->stamp($record->submitted_at ?? $record->updated_at),
                'url' => route('projects.purchase-requests.show', [$record->project_id, $record->id]),
            ],
            'purchase_orders' => [
                'number' => $record->po_number,
                'date' => $record->po_date?->toDateString(),
                'summary' => trim(($record->vendor_name_snapshot ?: $record->supplier_name).($record->subject ? ' - '.$record->subject : '')),
                'amount' => (string) $record->grand_total,
                'requested_by' => $record->createdBy?->name,
                'flag' => null,
                'waiting_since' => $this->stamp($record->submitted_at ?? $record->updated_at),
                'url' => route('projects.purchase-orders.show', [$record->project_id, $record->id]),
            ],
            'work_orders' => [
                'number' => $record->work_order_number,
                'date' => $record->work_order_date?->toDateString(),
                'summary' => trim($record->contractor_name.($record->scope ? ' - '.$record->scope : '')),
                'amount' => (string) $record->grand_total,
                'requested_by' => $record->createdBy?->name,
                'flag' => $record->is_quantity_exceeded ? 'Exceeds BOQ quantity' : null,
                'waiting_since' => $this->stamp($record->submitted_at ?? $record->updated_at),
                'url' => route('projects.work-orders.show', [$record->project_id, $record->id]),
            ],
            'grns' => [
                'number' => $record->receipt_number,
                'date' => $record->receipt_date?->toDateString(),
                'summary' => trim(($record->purchaseOrder?->po_number ?? '').' '.($record->purchaseOrder?->vendor_name_snapshot ?? '')),
                'amount' => null,
                'requested_by' => $record->submitter?->name ?? $record->received_by,
                'flag' => null,
                'waiting_since' => $this->stamp($record->submitted_at ?? $record->updated_at),
                'url' => route('projects.grns.show', [$record->project_id, $record->id]),
            ],
            'material_issues' => [
                'number' => $record->issue_number,
                'date' => $record->issue_date?->toDateString(),
                'summary' => 'To '.$record->issued_to.($record->purpose ? ' - '.$record->purpose : ''),
                'amount' => null,
                'requested_by' => $record->creator?->name,
                'flag' => null,
                'waiting_since' => $this->stamp($record->created_at),
                'url' => route('projects.inventory.issues.show', [$record->project_id, $record->id]),
            ],
            'stock_adjustments' => [
                'number' => $record->adjustment_number,
                'date' => $record->adjustment_date?->toDateString(),
                'summary' => ucfirst($record->adjustment_type).' '.rtrim(rtrim((string) $record->quantity, '0'), '.').' '.($record->material?->unit ?? '').' '.($record->material?->name ?? ''),
                'amount' => null,
                'requested_by' => $record->creator?->name,
                'flag' => null,
                'waiting_since' => $this->stamp($record->created_at),
                'url' => route('projects.inventory.adjustments.show', [$record->project_id, $record->id]),
            ],
            'internal_delivery_notes' => [
                'number' => $record->idn_number,
                'date' => $record->transfer_date?->toDateString(),
                'summary' => ($record->sourceLocation?->name ?? '-').' to '.($record->destinationLocation?->name ?? '-'),
                'amount' => null,
                'requested_by' => $record->requester?->name,
                'flag' => null,
                'waiting_since' => $this->stamp($record->submitted_at ?? $record->updated_at),
                'url' => route('inventory.transfers.show', $record->id),
            ],
        }];
    }

    private function stamp(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d H:i') : null;
    }

    /**
     * @return list<int>|null
     */
    private function projectIds(User $user): ?array
    {
        if ($this->access->seesAllProjects($user)) {
            return null;
        }

        return $user->projects()->pluck('projects.id')->map(fn ($id) => (int) $id)->all();
    }
}
