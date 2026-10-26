<?php

namespace App\Services;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ClientPayment;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Expense;
use App\Models\InternalDeliveryNote;
use App\Models\InventoryLocation;
use App\Models\Material;
use App\Models\MaterialIssue;
use App\Models\MaterialReceipt;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\WorkOrder;
use App\Services\Reports\ReportCatalog;
use App\Support\Listing;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * ERP-wide search. Every module is gated by the same permission as its
 * destination page and scoped to the projects the user can open, so results
 * never point at records the normal screens would refuse.
 */
class GlobalSearch
{
    public const MIN_LENGTH = 2;

    public const PER_MODULE = 5;

    public const MAX_RESULTS = 60;

    /** @var list<int>|null */
    private ?array $projectIds = null;

    private bool $seesAll = true;

    public function __construct(private Access $access) {}

    /**
     * @return array{query: string, total: int, groups: list<array{key: string, label: string, items: list<array<string, string|null>>, more_url: ?string}>}
     */
    public function search(User $user, string $term): array
    {
        $term = trim($term);
        if (mb_strlen($term) < self::MIN_LENGTH) {
            return ['query' => $term, 'total' => 0, 'groups' => []];
        }

        $this->projectIds = $this->access->visibleProjectIds($user);
        $this->seesAll = $this->projectIds === null;

        $groups = [];
        $total = 0;

        foreach ($this->modules() as $key => $module) {
            if ($total >= self::MAX_RESULTS) {
                break;
            }
            if (! $this->access->allows($user, $module['permission'])) {
                continue;
            }

            $rows = $module['run']($term, $user);
            $hasMore = count($rows) > self::PER_MODULE;
            $items = array_slice($rows, 0, min(self::PER_MODULE, self::MAX_RESULTS - $total));
            if ($items === []) {
                continue;
            }

            $groups[] = [
                'key' => $key,
                'label' => $module['label'],
                'items' => $items,
                'more_url' => $hasMore && isset($module['more']) ? ($module['more'])($term) : null,
            ];
            $total += count($items);
        }

        return ['query' => $term, 'total' => $total, 'groups' => $groups];
    }

    /**
     * @return array<string, array{label: string, permission: string, run: callable, more?: callable}>
     */
    private function modules(): array
    {
        return [
            'projects' => [
                'label' => 'Projects',
                'permission' => 'projects.view',
                'run' => fn (string $q, User $user) => $this->fetch(
                    Project::query()->visibleTo($user)->select(['id', 'project_code', 'project_name']),
                    $q,
                    ['project_code', 'project_name'],
                    'project_code',
                    fn (Project $p) => $this->item('project', $p->project_name, $p->project_code, route('projects.show', $p, false)),
                ),
                'more' => fn (string $q) => route('projects.index', ['search' => $q], false),
            ],
            'boqs' => [
                'label' => 'BOQ',
                'permission' => 'boq.view',
                'run' => fn (string $q) => $this->fetch(
                    $this->inProjects(Boq::query())->with('project:id,project_code')->select(['id', 'project_id', 'boq_number', 'title']),
                    $q,
                    ['boq_number', 'title'],
                    'boq_number',
                    fn (Boq $b) => $this->item('boq', $b->title, trim($b->boq_number.' · '.$b->project?->project_code, ' ·'), route('projects.boqs.show', [$b->project_id, $b->id], false)),
                ),
            ],
            'boq_items' => [
                'label' => 'BOQ Items',
                'permission' => 'boq.view',
                'run' => fn (string $q) => $this->fetch(
                    BoqItem::query()
                        ->whereHas('boq', fn ($boq) => $this->inProjects($boq)->whereColumn('boqs.current_revision_id', 'boq_items.boq_revision_id'))
                        ->with('boq:id,project_id,boq_number')
                        ->select(['id', 'boq_id', 'boq_revision_id', 'item_number', 'service_code', 'description', 'unit']),
                    $q,
                    ['item_number', 'service_code', 'description'],
                    'service_code',
                    fn (BoqItem $i) => $this->item(
                        'boq_item',
                        Str::limit(preg_replace('/\s+/', ' ', (string) $i->description), 90),
                        trim(implode(' · ', array_filter([$i->boq?->boq_number, $i->item_number ? 'Item '.$i->item_number : null, $i->service_code, $i->unit]))),
                        route('projects.boqs.show', [$i->boq->project_id, $i->boq_id], false),
                    ),
                ),
            ],
            'purchase_requests' => [
                'label' => 'Purchase Requests',
                'permission' => 'purchase_requests.view',
                'run' => fn (string $q) => $this->fetch(
                    $this->inProjects(PurchaseRequest::query())->with('project:id,project_code')->select(['id', 'project_id', 'pr_number', 'status', 'request_date']),
                    $q,
                    ['pr_number', 'status', 'request_date', 'items.name', 'items.description'],
                    'pr_number',
                    fn (PurchaseRequest $r) => $this->item('purchase_request', $r->pr_number, $this->parts($r->project?->project_code, $r->request_date?->toDateString(), $this->status($r->status)), route('projects.purchase-requests.show', [$r->project_id, $r->id], false)),
                ),
            ],
            'purchase_orders' => [
                'label' => 'Purchase Orders',
                'permission' => 'purchase_orders.view',
                'run' => fn (string $q) => $this->fetch(
                    $this->inProjects(PurchaseOrder::query())->with('project:id,project_code')->select(['id', 'project_id', 'po_number', 'supplier_name', 'vendor_name_snapshot', 'status', 'po_date']),
                    $q,
                    ['po_number', 'supplier_name', 'vendor_name_snapshot', 'status', 'po_date', 'items.name', 'items.description', 'vendor.name'],
                    'po_number',
                    fn (PurchaseOrder $o) => $this->item('purchase_order', $o->po_number, $this->parts($o->vendor_name_snapshot ?: $o->supplier_name, $o->project?->project_code, $this->status($o->status)), route('projects.purchase-orders.show', [$o->project_id, $o->id], false)),
                ),
            ],
            'work_orders' => [
                'label' => 'Work Orders',
                'permission' => 'work_orders.view',
                'run' => fn (string $q) => $this->fetch(
                    $this->inProjects(WorkOrder::query())->with('project:id,project_code')->select(['id', 'project_id', 'work_order_number', 'contractor_name', 'status']),
                    $q,
                    ['work_order_number', 'contractor_name', 'status'],
                    'work_order_number',
                    fn (WorkOrder $w) => $this->item('work_order', $w->work_order_number, $this->parts($w->contractor_name, $w->project?->project_code, $this->status($w->status)), route('projects.work-orders.show', [$w->project_id, $w->id], false)),
                ),
            ],
            'grns' => [
                'label' => 'GRNs',
                'permission' => 'grn.view',
                'run' => fn (string $q) => $this->fetch(
                    $this->inProjects(MaterialReceipt::query()->where('document_kind', MaterialReceipt::KIND_GRN))
                        ->with(['project:id,project_code', 'purchaseOrder:id,po_number'])
                        ->select(['id', 'project_id', 'purchase_order_id', 'receipt_number', 'challan_number', 'status', 'receipt_date']),
                    $q,
                    ['receipt_number', 'challan_number', 'status', 'receipt_date', 'purchaseOrder.po_number', 'vendor.name'],
                    'receipt_number',
                    fn (MaterialReceipt $r) => $this->item('grn', $r->receipt_number, $this->parts($r->purchaseOrder?->po_number, $r->project?->project_code, $this->status($r->status)), route('projects.grns.show', [$r->project_id, $r->id], false)),
                ),
            ],
            'store_receipts' => [
                'label' => 'Store Receipts',
                'permission' => 'store.view',
                'run' => fn (string $q) => $this->fetch(
                    $this->inProjects(MaterialReceipt::query()->where(fn ($kind) => $kind->whereNull('document_kind')->orWhere('document_kind', '!=', MaterialReceipt::KIND_GRN)))
                        ->with('project:id,project_code')
                        ->select(['id', 'project_id', 'receipt_number', 'received_by', 'receipt_date']),
                    $q,
                    ['receipt_number', 'received_by', 'receipt_date', 'purchaseOrder.po_number', 'vendor.name'],
                    'receipt_number',
                    fn (MaterialReceipt $r) => $this->item('store_receipt', $r->receipt_number, $this->parts($r->received_by, $r->project?->project_code, $r->receipt_date?->toDateString()), route('projects.store.show', [$r->project_id, $r->id], false)),
                ),
            ],
            'materials' => [
                'label' => 'Inventory Materials',
                'permission' => 'inventory.view',
                'run' => function (string $q) {
                    return $this->fetch(
                        Material::query()->select(['id', 'material_code', 'name', 'unit', 'category']),
                        $q,
                        ['material_code', 'name', 'category'],
                        'material_code',
                        fn (Material $m) => $this->item('material', $m->name, $this->parts($m->material_code, $m->unit, $m->category), route('inventory.materials.index', ['search' => $m->material_code], false)),
                    );
                },
            ],
            'stock_adjustments' => [
                'label' => 'Stock Adjustments',
                'permission' => 'inventory.view',
                'run' => fn (string $q) => $this->fetch(
                    $this->inProjects(StockAdjustment::query(), true)->with(['material:id,material_code,name'])->select(['id', 'project_id', 'material_id', 'adjustment_number', 'adjustment_type', 'status']),
                    $q,
                    ['adjustment_number', 'adjustment_type', 'status', 'material.name', 'material.material_code'],
                    'adjustment_number',
                    fn (StockAdjustment $a) => $this->item(
                        'stock_adjustment',
                        $a->adjustment_number,
                        $this->parts($a->material?->name, $this->status($a->adjustment_type), $this->status($a->status)),
                        $a->project_id
                            ? route('projects.inventory.adjustments.show', [$a->project_id, $a->id], false)
                            : route('inventory.central.adjustments.show', $a->id, false)
                    ),
                ),
            ],
            'material_issues' => [
                'label' => 'Material Issues',
                'permission' => 'inventory.view',
                'run' => fn (string $q) => $this->fetch(
                    $this->inProjects(MaterialIssue::query())->with('project:id,project_code')->select(['id', 'project_id', 'issue_number', 'issued_to', 'status']),
                    $q,
                    ['issue_number', 'issued_to', 'purpose', 'status', 'items.material.name', 'items.material.material_code'],
                    'issue_number',
                    fn (MaterialIssue $i) => $this->item('material_issue', $i->issue_number, $this->parts($i->issued_to, $i->project?->project_code, $this->status($i->status)), route('projects.inventory.issues.show', [$i->project_id, $i->id], false)),
                ),
            ],
            'transfers' => [
                'label' => 'Internal Delivery Notes',
                'permission' => 'idn.view',
                'run' => fn (string $q) => $this->fetch(
                    $this->transferScope(InternalDeliveryNote::query())
                        ->with(['sourceLocation:id,name', 'destinationLocation:id,name'])
                        ->select(['id', 'idn_number', 'status', 'source_location_id', 'destination_location_id']),
                    $q,
                    ['idn_number', 'status', 'sourceLocation.name', 'destinationLocation.name', 'items.material.name'],
                    'idn_number',
                    fn (InternalDeliveryNote $n) => $this->item('transfer', $n->idn_number, $this->parts(trim(($n->sourceLocation?->name ?? '—').' → '.($n->destinationLocation?->name ?? '—')), $this->status($n->status)), route('inventory.transfers.show', $n->id, false)),
                ),
            ],
            'locations' => [
                'label' => 'Stores & Locations',
                'permission' => 'inventory.view',
                'run' => fn (string $q) => $this->fetch(
                    $this->locationScope(InventoryLocation::query()->where('is_system', false))
                        ->with('project:id,project_code')
                        ->select(['id', 'project_id', 'location_code', 'name', 'location_type']),
                    $q,
                    ['location_code', 'name', 'location_type'],
                    'location_code',
                    fn (InventoryLocation $l) => $this->item('location', $l->name, $this->parts($l->location_code, $this->status($l->location_type), $l->project?->project_code), route('inventory.locations.index', ['search' => $l->location_code], false)),
                ),
            ],
            'vendors' => [
                'label' => 'Vendors',
                'permission' => 'vendors.view',
                'run' => fn (string $q) => $this->fetch(
                    Vendor::query()->select(['id', 'vendor_code', 'name', 'gst_number', 'city', 'status']),
                    $q,
                    ['vendor_code', 'name', 'gst_number', 'phone', 'city'],
                    'vendor_code',
                    fn (Vendor $v) => $this->item('vendor', $v->name, $this->parts($v->vendor_code, $v->gst_number, $v->city), route('vendors.show', $v->id, false)),
                ),
                'more' => fn (string $q) => route('vendors.index', ['search' => $q], false),
            ],
            'vendor_payments' => [
                'label' => 'Vendor Payments & Advances',
                'permission' => 'vendors.view',
                'run' => fn (string $q) => $this->fetch(
                    $this->inProjects(VendorPayment::query(), nullableProject: false)
                        ->with('vendor:id,name')
                        ->select(['id', 'vendor_id', 'reference_number', 'payment_type', 'payment_date', 'amount']),
                    $q,
                    ['reference_number', 'payment_type', 'payment_mode', 'payment_date', 'amount', 'vendor.name', 'invoice.invoice_number'],
                    'reference_number',
                    fn (VendorPayment $p) => $this->item(
                        'vendor_payment',
                        $p->reference_number ?: ($p->vendor?->name ?? 'Payment'),
                        $this->parts($p->vendor?->name, $this->status($p->payment_type), $p->payment_date?->toDateString()),
                        route('vendors.show', ['vendor' => $p->vendor_id, 'tab' => $p->payment_type === 'advance' ? 'advances' : 'payments'], false),
                    ),
                ),
            ],
            'purchase_invoices' => [
                'label' => 'Purchase Invoices',
                'permission' => 'invoices.view',
                'run' => fn (string $q) => $this->fetch(
                    $this->inProjects(PurchaseInvoice::query())->with('vendor:id,name')->select(['id', 'project_id', 'vendor_id', 'invoice_number', 'status', 'approval_status']),
                    $q,
                    ['invoice_number', 'status', 'approval_status', 'invoice_date', 'vendor.name', 'purchaseOrder.po_number'],
                    'invoice_number',
                    fn (PurchaseInvoice $i) => $this->item('purchase_invoice', $i->invoice_number, $this->parts($i->vendor?->name, $this->status($i->approval_status ?: $i->status)), route('projects.purchase-invoices.show', [$i->project_id, $i->id], false)),
                ),
            ],
            'debit_notes' => [
                'label' => 'Debit Notes',
                'permission' => 'debit_notes.view',
                'run' => fn (string $q) => $this->fetch(
                    $this->inProjects(DebitNote::query())->with('vendor:id,name')->select(['id', 'project_id', 'vendor_id', 'number', 'status']),
                    $q,
                    ['number', 'status', 'vendor.name', 'purchaseInvoice.invoice_number'],
                    'number',
                    fn (DebitNote $n) => $this->item('debit_note', $n->number, $this->parts($n->vendor?->name, $this->status($n->status)), route('projects.debit-notes.show', [$n->project_id, $n->id], false)),
                ),
            ],
            'credit_notes' => [
                'label' => 'Credit Notes',
                'permission' => 'credit_notes.view',
                'run' => fn (string $q) => $this->fetch(
                    $this->inProjects(CreditNote::query())->with('vendor:id,name')->select(['id', 'project_id', 'vendor_id', 'number', 'status']),
                    $q,
                    ['number', 'status', 'vendor.name', 'purchaseInvoice.invoice_number'],
                    'number',
                    fn (CreditNote $n) => $this->item('credit_note', $n->number, $this->parts($n->vendor?->name, $this->status($n->status)), route('projects.credit-notes.show', [$n->project_id, $n->id], false)),
                ),
            ],
            'clients' => [
                'label' => 'Clients',
                'permission' => 'clients.view',
                'run' => fn (string $q) => $this->fetch(
                    Client::query()->select(['id', 'client_code', 'name', 'gst_number', 'city']),
                    $q,
                    ['client_code', 'name', 'gst_number', 'phone', 'email'],
                    'client_code',
                    fn (Client $c) => $this->item('client', $c->name, $this->parts($c->client_code, $c->gst_number, $c->city), route('clients.show', $c->id, false)),
                ),
            ],
            'client_invoices' => [
                'label' => 'Client Invoices',
                'permission' => 'client_invoices.view',
                'run' => fn (string $q) => $this->fetch(
                    $this->inProjects(ClientInvoice::query())->with('project:id,project_code')->select(['id', 'project_id', 'invoice_number', 'client_name', 'approval_status', 'payment_status']),
                    $q,
                    ['invoice_number', 'client_name', 'reference_number', 'approval_status', 'payment_status', 'client.name'],
                    'invoice_number',
                    fn (ClientInvoice $i) => $this->item('client_invoice', $i->invoice_number, $this->parts($i->client_name, $i->project?->project_code, $this->status($i->approval_status)), route('projects.client-invoices.show', [$i->project_id, $i->id], false)),
                ),
            ],
            'client_payments' => [
                'label' => 'Client Receipts',
                'permission' => 'client_invoices.view',
                'run' => fn (string $q) => $this->fetch(
                    $this->inProjects(ClientPayment::query())->whereNotNull('client_invoice_id')
                        ->with(['client:id,name', 'invoice:id,project_id,invoice_number'])
                        ->select(['id', 'client_id', 'client_invoice_id', 'project_id', 'reference_number', 'payment_date']),
                    $q,
                    ['reference_number', 'payment_mode', 'payment_date', 'client.name', 'invoice.invoice_number'],
                    'reference_number',
                    fn (ClientPayment $p) => $p->invoice ? $this->item('client_payment', $p->reference_number ?: $p->invoice->invoice_number, $this->parts($p->client?->name, $p->invoice->invoice_number, $p->payment_date?->toDateString()), route('projects.client-invoices.show', [$p->invoice->project_id, $p->invoice->id], false)) : null,
                ),
            ],
            'expenses' => [
                'label' => 'Expenses',
                'permission' => 'expenses.view',
                'run' => fn (string $q) => $this->fetch(
                    $this->inProjects(Expense::query(), nullableProject: true)
                        ->with(['category:id,name', 'project:id,project_code'])
                        ->select(['id', 'project_id', 'category_id', 'number', 'payee', 'status']),
                    $q,
                    ['number', 'payee', 'description', 'reference_number', 'status', 'category.name', 'vendor.name'],
                    'number',
                    fn (Expense $e) => $this->item(
                        'expense',
                        $e->number,
                        $this->parts($e->category?->name, $e->payee, $e->project?->project_code, $this->status($e->status)),
                        $e->project_id ? route('projects.expenses.show', [$e->project_id, $e->id], false) : route('expenses.show', $e->id, false),
                    ),
                ),
            ],
            'users' => [
                'label' => 'Users',
                'permission' => 'users.view',
                'run' => fn (string $q, User $user) => $this->fetch(
                    User::query()->select(['id', 'name', 'email', 'designation']),
                    $q,
                    ['name', 'email', 'designation'],
                    'name',
                    fn (User $u) => $this->item('user', $u->name, $this->parts($u->email, $u->designation), $this->access->allows($user, 'users.edit') ? route('users.edit', $u->id, false) : route('users.index', ['search' => $u->email], false)),
                ),
            ],
            'reports' => [
                'label' => 'Reports',
                'permission' => 'reports.view',
                'run' => function (string $q) {
                    $needle = mb_strtolower($q);

                    return collect(ReportCatalog::all())
                        ->filter(fn (array $report) => str_contains(mb_strtolower($report['title'].' '.$report['group']), $needle))
                        ->take(self::PER_MODULE + 1)
                        ->map(fn (array $report) => $this->item('report', $report['title'], $report['group'], route('reports.show', ['report' => $report['id']], false)))
                        ->values()
                        ->all();
                },
            ],
        ];
    }

    /**
     * @param  list<string>  $columns  allowlisted columns / relation paths
     * @return list<array<string, string|null>>
     */
    private function fetch(Builder $query, string $term, array $columns, string $primary, callable $map): array
    {
        Listing::applySearch($query, $term, $columns);

        $wrapped = $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($primary));
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
        $query->orderByRaw(
            "case when {$wrapped} = ? then 0 when {$wrapped} like ? escape '!' then 1 else 2 end",
            [$term, $escaped.'%'],
        )->orderByDesc($query->qualifyColumn('id'));

        return $query->limit(self::PER_MODULE + 1)->get()->map($map)->filter()->values()->all();
    }

    private function inProjects(Builder $query, bool $nullableProject = false): Builder
    {
        if ($this->seesAll) {
            return $query;
        }

        $ids = $this->projectIds ?: [0];
        $column = $query->qualifyColumn('project_id');

        return $nullableProject
            ? $query->where(fn ($inner) => $inner->whereNull($column)->orWhereIn($column, $ids))
            : $query->whereIn($column, $ids);
    }

    /**
     * A transfer opens only when every project on it is accessible.
     */
    private function transferScope(Builder $query): Builder
    {
        if ($this->seesAll) {
            return $query;
        }

        $ids = $this->projectIds ?: [0];

        return $query
            ->where(fn ($q) => $q->whereNotNull('source_project_id')->orWhereNotNull('destination_project_id'))
            ->where(fn ($q) => $q->whereNull('source_project_id')->orWhereIn('source_project_id', $ids))
            ->where(fn ($q) => $q->whereNull('destination_project_id')->orWhereIn('destination_project_id', $ids));
    }

    private function locationScope(Builder $query): Builder
    {
        if ($this->seesAll) {
            return $query;
        }

        return $query->whereIn('project_id', $this->projectIds ?: [0]);
    }

    private function firstProjectId(): ?int
    {
        $query = Project::query()->orderBy('project_code');
        if (! $this->seesAll) {
            $query->whereIn('id', $this->projectIds ?: [0]);
        }

        $id = $query->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return array<string, string|null>
     */
    private function item(string $type, ?string $title, ?string $subtitle, string $url): array
    {
        return [
            'type' => $type,
            'title' => (string) ($title ?: '—'),
            'subtitle' => $subtitle ?: null,
            'url' => $url,
        ];
    }

    private function parts(?string ...$parts): string
    {
        return implode(' · ', array_filter($parts, fn ($part) => $part !== null && trim($part) !== ''));
    }

    private function status(?string $status): ?string
    {
        return $status ? Str::headline($status) : null;
    }
}
