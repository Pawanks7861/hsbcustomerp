<?php

namespace App\Services\Reports;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\ClientInvoice;
use App\Models\ClientPayment;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Material;
use App\Models\MaterialReceiptItem;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\StockLedgerEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\Access;
use App\Services\InventoryStock;
use App\Services\VendorBalanceService;
use App\Support\DocumentStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportQueryService
{
    public function __construct(
        private Access $access,
        private VendorBalanceService $balances,
        private InventoryStock $stock,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, totals?: array<string, mixed>, sections?: list<array<string, mixed>>}
     */
    public function run(string $report, User $user, array $filters): array
    {
        return match ($report) {
            'project-summary' => $this->projectSummary($user, $filters['from'] ?? null, $filters['to'] ?? null),
            'procurement-summary' => $this->procurementSummary(
                $user,
                isset($filters['project_id']) ? (int) $filters['project_id'] : null,
                $filters['from'] ?? null,
                $filters['to'] ?? null,
            ),
            'boq-quantity-consumption' => $this->boqQuantityConsumption($user, (int) ($filters['project_id'] ?? 0)),
            'purchase-order-register' => $this->purchaseOrderRegister($user, $filters),
            'invoice-outstanding' => $this->invoiceOutstandingReport($user, $filters),
            'expense-summary' => $this->expenseSummary($user, $filters),
            'vendor-outstanding' => $this->vendorOutstanding($user, $filters),
            'debit-credit-note-register' => $this->debitCreditNoteRegister($user, $filters),
            'client-invoice-register' => $this->clientInvoiceRegister($user, $filters),
            'client-outstanding' => $this->clientOutstanding($user, $filters),
            'client-payment-register' => $this->clientPaymentRegister($user, $filters),
            'stock-balance' => $this->stockBalance($user, (int) ($filters['project_id'] ?? 0)),
            default => throw ValidationException::withMessages(['report' => 'Unknown report.']),
        };
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function projectSummary(User $user, ?string $from = null, ?string $to = null): array
    {
        $projects = Project::query()->visibleTo($user)->orderBy('project_name')->get();
        $rows = [];
        $totals = [
            'boq_total' => '0.00',
            'po_total' => '0.00',
            'invoice_total' => '0.00',
            'expense_total' => '0.00',
            'wo_total' => '0.00',
        ];

        foreach ($projects as $project) {
            $boqTotal = '0.00';
            foreach (Boq::query()->where('project_id', $project->id)->get() as $boq) {
                $boqTotal = Money::add($boqTotal, $boq->grandTotal());
            }

            $poQuery = PurchaseOrder::query()
                ->where('project_id', $project->id)
                ->where('status', DocumentStatus::APPROVED);
            $this->applyDateRange($poQuery, 'po_date', $from, $to);
            $poTotal = Money::scale((string) $poQuery->sum('grand_total'), 2) ?? '0.00';

            $invoiceQuery = PurchaseInvoice::query()
                ->where('project_id', $project->id)
                ->where('approval_status', PurchaseInvoice::APPROVAL_APPROVED);
            $this->applyDateRange($invoiceQuery, 'invoice_date', $from, $to);
            $invoiceTotal = Money::scale((string) $invoiceQuery->sum('total_amount'), 2) ?? '0.00';

            $expenseQuery = Expense::query()
                ->where('project_id', $project->id)
                ->where('status', DocumentStatus::APPROVED);
            $this->applyDateRange($expenseQuery, 'date', $from, $to);
            $expenseTotal = Money::scale((string) $expenseQuery->sum('total_amount'), 2) ?? '0.00';

            $woQuery = WorkOrder::query()
                ->where('project_id', $project->id)
                ->whereIn('status', WorkOrder::ALLOCATING);
            $this->applyDateRange($woQuery, 'work_order_date', $from, $to);
            $woTotal = Money::scale((string) $woQuery->sum('grand_total'), 2) ?? '0.00';

            $row = [
                'project_id' => $project->id,
                'project_code' => $project->project_code,
                'project_name' => $project->project_name,
                'boq_total' => $boqTotal,
                'po_total' => $poTotal,
                'invoice_total' => $invoiceTotal,
                'expense_total' => $expenseTotal,
                'wo_total' => $woTotal,
            ];
            $rows[] = $row;

            foreach (array_keys($totals) as $key) {
                $totals[$key] = Money::add($totals[$key], (string) $row[$key]);
            }
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function procurementSummary(User $user, ?int $projectId = null, ?string $from = null, ?string $to = null): array
    {
        if ($projectId !== null) {
            $this->ensureProjectAccess($user, $projectId);
        }

        $prQuery = PurchaseRequest::query();
        $this->scopeProject($prQuery, $user, $projectId);
        $this->applyDateRange($prQuery, 'request_date', $from, $to);
        $prCount = (int) $prQuery->count();

        $poQuery = PurchaseOrder::query();
        $this->scopeProject($poQuery, $user, $projectId);
        $this->applyDateRange($poQuery, 'po_date', $from, $to);
        $poCount = (int) $poQuery->count();
        $poValue = Money::scale((string) $poQuery->sum('grand_total'), 2) ?? '0.00';

        $orderedQuery = PurchaseOrderItem::query()
            ->whereHas('purchaseOrder', function (Builder $order) use ($user, $projectId, $from, $to): void {
                $this->scopeProject($order, $user, $projectId);
                $this->applyDateRange($order, 'po_date', $from, $to);
            });
        $orderedQty = Money::scale((string) $orderedQuery->sum('quantity'), 3) ?? '0.000';

        $invoicedQuery = PurchaseInvoiceItem::query()
            ->whereHas('invoice', function (Builder $invoice) use ($user, $projectId, $from, $to): void {
                $this->scopeProject($invoice, $user, $projectId);
                $this->applyDateRange($invoice, 'invoice_date', $from, $to);
            });
        $invoicedQty = Money::scale((string) $invoicedQuery->sum('quantity'), 3) ?? '0.000';

        $rows = [
            ['label' => 'Purchase requests', 'value' => (string) $prCount],
            ['label' => 'Purchase orders', 'value' => (string) $poCount],
            ['label' => 'PO value', 'value' => $poValue],
            ['label' => 'Ordered quantity', 'value' => $orderedQty],
            ['label' => 'Invoiced quantity', 'value' => $invoicedQty],
        ];

        return [
            'rows' => $rows,
            'totals' => [
                'purchase_requests' => (string) $prCount,
                'purchase_orders' => (string) $poCount,
                'po_value' => $poValue,
                'ordered_quantity' => $orderedQty,
                'invoiced_quantity' => $invoicedQty,
            ],
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function boqQuantityConsumption(User $user, int $projectId): array
    {
        $project = $this->projectOrFail($user, $projectId);

        $boqIds = Boq::query()->where('project_id', $project->id)->pluck('id');
        $items = BoqItem::query()
            ->whereIn('boq_id', $boqIds)
            ->where('is_section', false)
            ->whereNotNull('quantity')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $itemIds = $items->pluck('id')->all();

        $requested = PurchaseRequestItem::query()
            ->whereIn('boq_item_id', $itemIds)
            ->select('boq_item_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('boq_item_id')
            ->pluck('total', 'boq_item_id');

        $ordered = PurchaseOrderItem::query()
            ->whereIn('boq_item_id', $itemIds)
            ->select('boq_item_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('boq_item_id')
            ->pluck('total', 'boq_item_id');

        $received = MaterialReceiptItem::query()
            ->whereHas('purchaseOrderItem', fn (Builder $q) => $q->whereIn('boq_item_id', $itemIds))
            ->join('purchase_order_items', 'purchase_order_items.id', '=', 'material_receipt_items.purchase_order_item_id')
            ->select('purchase_order_items.boq_item_id', DB::raw('SUM(material_receipt_items.quantity_received) as total'))
            ->groupBy('purchase_order_items.boq_item_id')
            ->pluck('total', 'boq_item_id');

        $invoiced = PurchaseInvoiceItem::query()
            ->whereHas('purchaseOrderItem', fn (Builder $q) => $q->whereIn('boq_item_id', $itemIds))
            ->join('purchase_order_items', 'purchase_order_items.id', '=', 'purchase_invoice_items.purchase_order_item_id')
            ->select('purchase_order_items.boq_item_id', DB::raw('SUM(purchase_invoice_items.quantity) as total'))
            ->groupBy('purchase_order_items.boq_item_id')
            ->pluck('total', 'boq_item_id');

        $rows = [];
        $totals = [
            'boq_quantity' => '0.000',
            'requested_quantity' => '0.000',
            'ordered_quantity' => '0.000',
            'received_quantity' => '0.000',
            'invoiced_quantity' => '0.000',
        ];

        foreach ($items as $item) {
            $boqQty = Money::scale($item->quantity, 3) ?? '0.000';
            $reqQty = Money::scale((string) ($requested[$item->id] ?? '0'), 3) ?? '0.000';
            $ordQty = Money::scale((string) ($ordered[$item->id] ?? '0'), 3) ?? '0.000';
            $recQty = Money::scale((string) ($received[$item->id] ?? '0'), 3) ?? '0.000';
            $invQty = Money::scale((string) ($invoiced[$item->id] ?? '0'), 3) ?? '0.000';

            $rows[] = [
                'item_number' => $item->item_number,
                'description' => $item->description,
                'unit' => $item->unit,
                'boq_quantity' => $boqQty,
                'requested_quantity' => $reqQty,
                'ordered_quantity' => $ordQty,
                'received_quantity' => $recQty,
                'invoiced_quantity' => $invQty,
            ];

            foreach ($totals as $key => $sum) {
                $totals[$key] = Money::add($sum, (string) $rows[array_key_last($rows)][$key], 3);
            }
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function purchaseOrderRegister(User $user, array $filters): array
    {
        $query = PurchaseOrder::query()
            ->with(['project:id,project_code', 'vendor:id,name'])
            ->orderByDesc('po_date')
            ->orderByDesc('id');

        $this->scopeProject($query, $user, isset($filters['project_id']) ? (int) $filters['project_id'] : null);

        if (! empty($filters['vendor_id'])) {
            $query->where('vendor_id', (int) $filters['vendor_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        $this->applyDateRange($query, 'po_date', $filters['from'] ?? null, $filters['to'] ?? null);

        $rows = [];
        $totals = ['grand_total' => '0.00', 'invoiced_total' => '0.00', 'remaining_billable' => '0.00'];

        foreach ($query->get() as $order) {
            $invoicedTotal = Money::scale((string) $order->invoices()->where('approval_status', PurchaseInvoice::APPROVAL_APPROVED)->sum('total_amount'), 2) ?? '0.00';
            $grand = Money::scale((string) $order->grand_total, 2) ?? '0.00';
            $remaining = Money::sub($grand, $invoicedTotal);
            if (bccomp($remaining, '0', 2) === -1) {
                $remaining = '0.00';
            }

            $rows[] = [
                'po_number' => $order->po_number,
                'po_date' => $order->po_date?->toDateString(),
                'project_code' => $order->project?->project_code,
                'vendor_name' => $order->vendor?->name ?? $order->vendor_name_snapshot,
                'status' => $order->status,
                'grand_total' => $grand,
                'invoiced_total' => $invoicedTotal,
                'remaining_billable' => $remaining,
            ];

            $totals['grand_total'] = Money::add($totals['grand_total'], $grand);
            $totals['invoiced_total'] = Money::add($totals['invoiced_total'], $invoicedTotal);
            $totals['remaining_billable'] = Money::add($totals['remaining_billable'], $remaining);
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function invoiceOutstandingReport(User $user, array $filters): array
    {
        $query = PurchaseInvoice::query()
            ->with(['project:id,project_code', 'vendor:id,name'])
            ->where('approval_status', PurchaseInvoice::APPROVAL_APPROVED)
            ->orderByDesc('invoice_date')
            ->orderByDesc('id');

        $this->scopeProject($query, $user, isset($filters['project_id']) ? (int) $filters['project_id'] : null);

        if (! empty($filters['vendor_id'])) {
            $query->where('vendor_id', (int) $filters['vendor_id']);
        }

        $this->applyDateRange($query, 'invoice_date', $filters['from'] ?? null, $filters['to'] ?? null);

        $rows = [];
        $totals = ['total_amount' => '0.00', 'paid_amount' => '0.00', 'outstanding' => '0.00'];

        foreach ($query->get() as $invoice) {
            $total = Money::scale((string) $invoice->total_amount, 2) ?? '0.00';
            $outstanding = $this->balances->invoiceOutstanding($invoice);
            $paid = Money::sub($total, $outstanding);
            if (bccomp($paid, '0', 2) === -1) {
                $paid = '0.00';
            }

            $rows[] = [
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'project_code' => $invoice->project?->project_code,
                'vendor_name' => $invoice->vendor?->name,
                'total_amount' => $total,
                'paid_amount' => $paid,
                'outstanding' => $outstanding,
                'status' => $invoice->status,
            ];

            $totals['total_amount'] = Money::add($totals['total_amount'], $total);
            $totals['paid_amount'] = Money::add($totals['paid_amount'], $paid);
            $totals['outstanding'] = Money::add($totals['outstanding'], $outstanding);
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{sections: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function expenseSummary(User $user, array $filters): array
    {
        $base = Expense::query()->where('status', DocumentStatus::APPROVED);
        $this->scopeProject($base, $user, isset($filters['project_id']) ? (int) $filters['project_id'] : null);
        $this->applyDateRange($base, 'date', $filters['from'] ?? null, $filters['to'] ?? null);

        $categoryIds = (clone $base)->select('category_id')->distinct()->pluck('category_id');
        $categories = ExpenseCategory::query()->whereIn('id', $categoryIds)->get()->keyBy('id');

        $byCategory = (clone $base)
            ->select('category_id', DB::raw('SUM(total_amount) as total_amount'))
            ->groupBy('category_id')
            ->get()
            ->map(fn ($row) => [
                'category' => $categories->get($row->category_id)?->name ?? 'Uncategorized',
                'total_amount' => Money::scale((string) $row->total_amount, 2) ?? '0.00',
            ])
            ->values()
            ->all();

        $projectGroups = (clone $base)
            ->select('project_id', DB::raw('SUM(total_amount) as total_amount'))
            ->groupBy('project_id')
            ->get();

        $projectIds = $projectGroups->pluck('project_id')->filter()->all();
        $projects = Project::query()->whereIn('id', $projectIds)->get()->keyBy('id');

        $byProject = $projectGroups
            ->map(function ($row) use ($projects): array {
                if ($row->project_id === null) {
                    return [
                        'project' => 'Company (no project)',
                        'project_code' => '—',
                        'total_amount' => Money::scale((string) $row->total_amount, 2) ?? '0.00',
                    ];
                }

                $project = $projects->get($row->project_id);

                return [
                    'project' => $project?->project_name,
                    'project_code' => $project?->project_code,
                    'total_amount' => Money::scale((string) $row->total_amount, 2) ?? '0.00',
                ];
            })
            ->values()
            ->all();

        $grand = Money::scale((string) (clone $base)->sum('total_amount'), 2) ?? '0.00';

        return [
            'rows' => [],
            'sections' => [
                [
                    'key' => 'by_category',
                    'title' => 'By category',
                    'columns' => [
                        ['key' => 'category', 'label' => 'Category'],
                        ['key' => 'total_amount', 'label' => 'Total', 'align' => 'right', 'money' => true],
                    ],
                    'rows' => $byCategory,
                ],
                [
                    'key' => 'by_project',
                    'title' => 'By project',
                    'columns' => [
                        ['key' => 'project_code', 'label' => 'Code'],
                        ['key' => 'project', 'label' => 'Project'],
                        ['key' => 'total_amount', 'label' => 'Total', 'align' => 'right', 'money' => true],
                    ],
                    'rows' => $byProject,
                ],
            ],
            'totals' => ['total_amount' => $grand],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function vendorOutstanding(User $user, array $filters): array
    {
        $projectId = isset($filters['project_id']) ? (int) $filters['project_id'] : null;
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        if ($projectId !== null) {
            $this->ensureProjectAccess($user, $projectId);
        }

        $vendors = Vendor::query()
            ->when(! empty($filters['vendor_id']), fn (Builder $q) => $q->whereKey((int) $filters['vendor_id']))
            ->orderBy('name')
            ->get();

        $rows = [];
        $totals = [
            'total_invoice_value' => '0.00',
            'invoice_payments' => '0.00',
            'outstanding_payable' => '0.00',
            'available_advance' => '0.00',
        ];

        foreach ($vendors as $vendor) {
            $summary = $this->balances->summary($vendor, $projectId, $from, $to);
            $rows[] = [
                'vendor_code' => $vendor->vendor_code,
                'name' => $vendor->name,
                ...$summary,
            ];

            foreach ($totals as $key => $sum) {
                $totals[$key] = Money::add($sum, $summary[$key] ?? '0.00');
            }
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function debitCreditNoteRegister(User $user, array $filters): array
    {
        $rows = [];

        foreach ([DebitNote::class => 'Debit', CreditNote::class => 'Credit'] as $model => $label) {
            /** @var Builder $query */
            $query = $model::query()
                ->with(['project:id,project_code', 'vendor:id,name'])
                ->orderByDesc('date')
                ->orderByDesc('id');

            $this->scopeProject($query, $user, isset($filters['project_id']) ? (int) $filters['project_id'] : null);

            if (! empty($filters['vendor_id'])) {
                $query->where('vendor_id', (int) $filters['vendor_id']);
            }

            if (! empty($filters['status'])) {
                $query->where('status', (string) $filters['status']);
            }

            $this->applyDateRange($query, 'date', $filters['from'] ?? null, $filters['to'] ?? null);

            foreach ($query->get() as $note) {
                $rows[] = [
                    'note_type' => $label,
                    'number' => $note->number,
                    'date' => $note->date?->toDateString(),
                    'project_code' => $note->project?->project_code,
                    'vendor_name' => $note->vendor?->name,
                    'status' => $note->status,
                    'total_amount' => Money::scale((string) $note->total_amount, 2) ?? '0.00',
                    '_sort' => ($note->date?->format('Ymd') ?? '').$note->id,
                ];
            }
        }

        usort($rows, fn (array $a, array $b) => strcmp((string) $b['_sort'], (string) $a['_sort']));
        $rows = array_map(function (array $row) {
            unset($row['_sort']);

            return $row;
        }, $rows);

        $totalAmount = '0.00';
        foreach ($rows as $row) {
            $totalAmount = Money::add($totalAmount, $row['total_amount']);
        }

        return ['rows' => $rows, 'totals' => ['total_amount' => $totalAmount]];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function clientInvoiceRegister(User $user, array $filters): array
    {
        $query = ClientInvoice::query()
            ->with(['project:id,project_code', 'client:id,name', 'payments'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id');

        $this->scopeProject($query, $user, isset($filters['project_id']) ? (int) $filters['project_id'] : null);
        if (! empty($filters['client_id'])) {
            $query->where('client_id', (int) $filters['client_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('approval_status', (string) $filters['status']);
        }
        $this->applyDateRange($query, 'invoice_date', $filters['from'] ?? null, $filters['to'] ?? null);

        $rows = [];
        $totals = ['grand_total' => '0.00', 'paid_amount' => '0.00', 'outstanding' => '0.00'];
        foreach ($query->get() as $invoice) {
            $posted = $invoice->isApproved();
            $total = Money::scale((string) $invoice->grand_total, 2) ?? '0.00';
            $paid = $posted ? $invoice->paidTotal() : '0.00';
            $outstanding = $posted ? $invoice->outstanding() : '0.00';
            $rows[] = [
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'project_code' => $invoice->project?->project_code,
                'client_name' => $invoice->client?->name ?? $invoice->client_name,
                'approval_status' => $invoice->approval_status,
                'payment_status' => $invoice->payment_status,
                'grand_total' => $posted ? $total : '0.00',
                'paid_amount' => $paid,
                'outstanding' => $outstanding,
            ];
            if ($posted) {
                $totals['grand_total'] = Money::add($totals['grand_total'], $total);
                $totals['paid_amount'] = Money::add($totals['paid_amount'], $paid);
                $totals['outstanding'] = Money::add($totals['outstanding'], $outstanding);
            }
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function clientOutstanding(User $user, array $filters): array
    {
        $query = ClientInvoice::query()
            ->with(['project:id,project_code', 'client:id,name', 'payments'])
            ->where('approval_status', DocumentStatus::APPROVED)
            ->where('payment_status', '!=', ClientInvoice::PAYMENT_PAID)
            ->orderBy('due_date')
            ->orderBy('id');

        $this->scopeProject($query, $user, isset($filters['project_id']) ? (int) $filters['project_id'] : null);
        if (! empty($filters['client_id'])) {
            $query->where('client_id', (int) $filters['client_id']);
        }
        $this->applyDateRange($query, 'invoice_date', $filters['from'] ?? null, $filters['to'] ?? null);

        $rows = [];
        $totals = ['grand_total' => '0.00', 'paid_amount' => '0.00', 'outstanding' => '0.00'];
        foreach ($query->get() as $invoice) {
            $total = Money::scale((string) $invoice->grand_total, 2) ?? '0.00';
            $paid = $invoice->paidTotal();
            $outstanding = $invoice->outstanding();
            if (bccomp($outstanding, '0.00', 2) !== 1) {
                continue;
            }
            $rows[] = [
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'project_code' => $invoice->project?->project_code,
                'client_name' => $invoice->client?->name ?? $invoice->client_name,
                'grand_total' => $total,
                'paid_amount' => $paid,
                'outstanding' => $outstanding,
                'overdue' => $invoice->isOverdue() ? 'Yes' : 'No',
            ];
            $totals['grand_total'] = Money::add($totals['grand_total'], $total);
            $totals['paid_amount'] = Money::add($totals['paid_amount'], $paid);
            $totals['outstanding'] = Money::add($totals['outstanding'], $outstanding);
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function clientPaymentRegister(User $user, array $filters): array
    {
        $query = ClientPayment::query()
            ->with(['client:id,name', 'project:id,project_code', 'invoice:id,invoice_number,approval_status'])
            ->whereHas('invoice', fn ($invoice) => $invoice->where('approval_status', DocumentStatus::APPROVED))
            ->orderByDesc('payment_date')
            ->orderByDesc('id');

        $this->scopeProject($query, $user, isset($filters['project_id']) ? (int) $filters['project_id'] : null);
        if (! empty($filters['client_id'])) {
            $query->where('client_id', (int) $filters['client_id']);
        }
        $this->applyDateRange($query, 'payment_date', $filters['from'] ?? null, $filters['to'] ?? null);

        $rows = [];
        $total = '0.00';
        foreach ($query->get() as $payment) {
            $amount = Money::scale((string) $payment->amount, 2) ?? '0.00';
            $rows[] = [
                'payment_date' => $payment->payment_date?->toDateString(),
                'invoice_number' => $payment->invoice?->invoice_number,
                'project_code' => $payment->project?->project_code,
                'client_name' => $payment->client?->name,
                'payment_mode' => $payment->payment_mode,
                'reference_number' => $payment->reference_number,
                'amount' => $amount,
            ];
            $total = Money::add($total, $amount);
        }

        return ['rows' => $rows, 'totals' => ['amount' => $total]];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function stockBalance(User $user, int $projectId): array
    {
        $project = $this->projectOrFail($user, $projectId);

        $materialIds = StockLedgerEntry::query()
            ->where('project_id', $project->id)
            ->distinct()
            ->pluck('material_id');

        $materials = Material::query()->whereIn('id', $materialIds)->orderBy('name')->get()->keyBy('id');
        $rows = [];
        $totals = ['available' => '0.000'];

        foreach ($materialIds as $materialId) {
            $material = $materials->get($materialId);
            if (! $material) {
                continue;
            }

            $available = $this->stock->balance($project->id, (int) $materialId);
            $received = Money::scale((string) StockLedgerEntry::query()
                ->where('project_id', $project->id)
                ->where('material_id', $materialId)
                ->where('transaction_type', StockLedgerEntry::RECEIPT)
                ->sum('stock_in'), 3) ?? '0.000';
            $issued = Money::scale((string) StockLedgerEntry::query()
                ->where('project_id', $project->id)
                ->where('material_id', $materialId)
                ->where('transaction_type', StockLedgerEntry::ISSUE)
                ->sum('stock_out'), 3) ?? '0.000';
            $rows[] = [
                'material_code' => $material->material_code,
                'material_name' => $material->name,
                'unit' => $material->unit,
                'total_received' => $received,
                'total_issued' => $issued,
                'available' => $available,
                'status' => $this->stockStatus($available),
            ];

            $totals['available'] = Money::add($totals['available'], $available, 3);
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    private function stockStatus(string $available): string
    {
        return bccomp($available, '0', 3) === 1 ? 'In stock' : 'Out of stock';
    }

    private function projectOrFail(User $user, int $projectId): Project
    {
        if ($projectId < 1) {
            throw ValidationException::withMessages(['project_id' => 'Select a project for this report.']);
        }

        $project = Project::query()->visibleTo($user)->whereKey($projectId)->first();
        if (! $project) {
            abort(404);
        }

        $this->ensureProjectAccess($user, $projectId);

        return $project;
    }

    private function ensureProjectAccess(User $user, int $projectId): void
    {
        $project = Project::query()->find($projectId);
        if ($project && ! $this->access->canAccessProject($user, $project)) {
            abort(404);
        }
    }

    /**
     * @param  Builder<PurchaseOrder>|Builder<PurchaseRequest>|Builder<PurchaseInvoice>|Builder<Expense>|Builder<DebitNote>|Builder<CreditNote>  $query
     */
    private function scopeProject(Builder $query, User $user, ?int $projectId): void
    {
        if ($projectId !== null) {
            $this->ensureProjectAccess($user, $projectId);
            $query->where('project_id', $projectId);

            return;
        }

        if (! $this->access->seesAllProjects($user)) {
            $query->whereIn('project_id', $user->projects()->select('projects.id'));
        }
    }

    private function applyDateRange(Builder $query, string $column, ?string $from, ?string $to): void
    {
        if ($from) {
            $query->whereDate($column, '>=', $from);
        }

        if ($to) {
            $query->whereDate($column, '<=', $to);
        }
    }
}
