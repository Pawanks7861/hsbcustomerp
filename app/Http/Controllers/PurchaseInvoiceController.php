<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Models\VendorAdvanceAdjustment;
use App\Services\Access;
use App\Services\DocumentPdf;
use App\Services\PurchaseInvoiceLineService;
use App\Services\VendorBalanceService;
use App\Support\DocumentStatus;
use App\Support\Listing;
use App\Support\Money;
use App\Support\PdfFormat;
use App\Support\StoredUpload;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseInvoiceController extends Controller
{
    public function index(Request $request, Project $project, VendorBalanceService $balances, Access $access): Response
    {
        abort_unless($access->allows($request->user(), 'invoices.view'), 403);

        $filters = Listing::filters($request);
        $query = $project->purchaseInvoices()->with(['vendor', 'purchaseOrder'])->latest('invoice_date')->orderByDesc('id');
        Listing::applySearch($query, $filters['search'], [
            'invoice_number', 'invoice_date', 'total_amount', 'status', 'approval_status',
            'purchaseOrder.po_number', 'vendor.name', 'vendor.vendor_code',
        ]);

        return Inertia::render('PurchaseInvoices/Index', [
            'project' => $this->projectCard($project),
            'filters' => $filters,
            'invoices' => Listing::paginate($query, $request)->through(fn (PurchaseInvoice $invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'po_number' => $invoice->purchaseOrder?->po_number,
                'vendor' => $invoice->vendor?->name,
                'total_amount' => (string) $invoice->total_amount,
                'paid' => $balances->directInvoicePayments($invoice),
                'outstanding' => $balances->invoiceOutstanding($invoice),
                'status' => $invoice->status,
                'approval_status' => $invoice->approval_status,
            ]),
        ]);
    }

    public function create(Request $request, Project $project, PurchaseInvoiceLineService $lines, Access $access): Response
    {
        abort_unless($access->allows($request->user(), 'invoices.create'), 403);

        $order = $this->orderForProject($project, $request->integer('purchase_order_id') ?: null, approved: true);

        return Inertia::render('PurchaseInvoices/Form', [
            'project' => $this->projectCard($project),
            'invoice' => null,
            'purchaseOrders' => $this->orderOptions($project),
            'selectedOrder' => $order ? $this->orderCard($order, $lines) : null,
            'vendors' => $this->vendorOptions(),
            'canRoundOff' => $access->allows($request->user(), 'invoices.round_off') || $access->isSuperAdmin($request->user()),
        ]);
    }

    public function store(Request $request, Project $project, PurchaseInvoiceLineService $lines, Access $access): RedirectResponse
    {
        abort_unless($access->allows($request->user(), 'invoices.create'), 403);

        $invoice = $this->persist($request, $project, null, $lines);

        return redirect()->route('projects.purchase-invoices.show', [$project, $invoice])->with('success', 'Purchase invoice saved as a draft.');
    }

    public function show(Request $request, Project $project, PurchaseInvoice $invoice, VendorBalanceService $balances, Access $access): Response
    {
        abort_unless($access->allows($request->user(), 'invoices.view'), 403);

        $this->ensureInvoice($project, $invoice);
        $invoice->load(['vendor', 'purchaseOrder', 'items', 'approver', 'rejectedBy']);
        $payload = $this->invoicePayload($invoice, $balances);

        return Inertia::render('PurchaseInvoices/Show', [
            'project' => $this->projectCard($project),
            'invoice' => $payload,
            'availableAdvance' => $balances->availableAdvance($invoice->vendor),
            'canSettle' => $invoice->isApproved() && bccomp($payload['outstanding'], '0.00', 2) === 1,
            'abilities' => [
                'edit' => $invoice->isEditable() && $access->allows($request->user(), 'invoices.edit'),
                'submit' => in_array($invoice->approval_status, [PurchaseInvoice::APPROVAL_DRAFT, PurchaseInvoice::APPROVAL_REJECTED], true)
                    && $access->allows($request->user(), 'invoices.submit'),
                'approve' => $invoice->approval_status === PurchaseInvoice::APPROVAL_SUBMITTED
                    && $access->allows($request->user(), 'invoices.approve'),
                'reject' => $invoice->approval_status === PurchaseInvoice::APPROVAL_SUBMITTED
                    && $access->allows($request->user(), 'invoices.reject'),
            ],
        ]);
    }

    public function pdf(Request $request, Project $project, PurchaseInvoice $invoice, VendorBalanceService $balances, DocumentPdf $pdf, Access $access): HttpResponse
    {
        abort_unless($access->allows($request->user(), 'invoices.print'), 403);

        return $this->renderPdf($project, $invoice, $balances, $pdf, false);
    }

    public function downloadPdf(Request $request, Project $project, PurchaseInvoice $invoice, VendorBalanceService $balances, DocumentPdf $pdf, Access $access): HttpResponse
    {
        abort_unless($access->allows($request->user(), 'invoices.print'), 403);

        return $this->renderPdf($project, $invoice, $balances, $pdf, true);
    }

    public function update(Request $request, Project $project, PurchaseInvoice $invoice, PurchaseInvoiceLineService $lines, Access $access): RedirectResponse
    {
        abort_unless($access->allows($request->user(), 'invoices.edit'), 403);

        $this->ensureInvoice($project, $invoice);

        if (! $invoice->isEditable()) {
            return back()->with('error', 'Only draft or rejected invoices without payments can be edited.');
        }

        $this->persist($request, $project, $invoice, $lines);

        return redirect()->route('projects.purchase-invoices.show', [$project, $invoice])->with('success', 'Invoice updated.');
    }

    public function destroy(Request $request, Project $project, PurchaseInvoice $invoice, Access $access): RedirectResponse
    {
        abort_unless($access->allows($request->user(), 'invoices.delete'), 403);

        $this->ensureInvoice($project, $invoice);

        if ($invoice->isApproved() || $invoice->hasSettlements()) {
            return back()->with('error', 'This invoice cannot be deleted because it is approved or a payment exists.');
        }

        if ($invoice->invoice_file_path) {
            Storage::disk('local')->delete($invoice->invoice_file_path);
        }

        $invoice->delete();

        return redirect()->route('projects.purchase-invoices.index', $project)->with('success', 'Invoice deleted.');
    }

    public function attachment(Project $project, PurchaseInvoice $invoice): StreamedResponse
    {
        $this->ensureInvoice($project, $invoice);
        abort_unless($invoice->invoice_file_path && Storage::disk('local')->exists($invoice->invoice_file_path), 404);

        return StoredUpload::serve(
            $invoice->invoice_file_path,
            inlineIfSafe: true,
            expectedPrefix: 'projects/'.$project->id.'/invoices',
        );
    }

    public function submit(Request $request, Project $project, PurchaseInvoice $invoice, Access $access): RedirectResponse
    {
        abort_unless($access->allows($request->user(), 'invoices.submit'), 403);
        $this->ensureInvoice($project, $invoice);

        if (! in_array($invoice->approval_status, [PurchaseInvoice::APPROVAL_DRAFT, PurchaseInvoice::APPROVAL_REJECTED], true)) {
            throw ValidationException::withMessages(['approval_status' => 'Only draft or rejected invoices can be submitted.']);
        }

        $invoice->update([
            'approval_status' => PurchaseInvoice::APPROVAL_SUBMITTED,
            'submitted_at' => now(),
            'rejected_at' => null,
            'rejected_by' => null,
            'rejection_reason' => null,
        ]);

        return back()->with('success', 'Invoice submitted for approval.');
    }

    public function approve(Request $request, Project $project, PurchaseInvoice $invoice, Access $access): RedirectResponse
    {
        abort_unless($access->allows($request->user(), 'invoices.approve'), 403);
        $this->ensureInvoice($project, $invoice);

        if ($invoice->approval_status !== PurchaseInvoice::APPROVAL_SUBMITTED) {
            throw ValidationException::withMessages(['approval_status' => 'Only submitted invoices can be approved.']);
        }

        $invoice->update([
            'approval_status' => PurchaseInvoice::APPROVAL_APPROVED,
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'rejected_at' => null,
            'rejected_by' => null,
            'rejection_reason' => null,
        ]);

        return back()->with('success', 'Invoice approved.');
    }

    public function reject(Request $request, Project $project, PurchaseInvoice $invoice, Access $access): RedirectResponse
    {
        abort_unless($access->allows($request->user(), 'invoices.reject'), 403);
        $this->ensureInvoice($project, $invoice);

        if ($invoice->approval_status !== PurchaseInvoice::APPROVAL_SUBMITTED) {
            throw ValidationException::withMessages(['approval_status' => 'Only submitted invoices can be rejected.']);
        }

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        $invoice->update([
            'approval_status' => PurchaseInvoice::APPROVAL_REJECTED,
            'rejected_at' => now(),
            'rejected_by' => $request->user()->id,
            'rejection_reason' => $validated['rejection_reason'],
            'approved_at' => null,
            'approved_by' => null,
        ]);

        return back()->with('success', 'Invoice rejected.');
    }

    public function applyAdvance(Request $request, Project $project, PurchaseInvoice $invoice, VendorBalanceService $balances): RedirectResponse
    {
        $this->ensureInvoice($project, $invoice);
        abort_unless($invoice->isApproved(), 403);
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $amount = Money::scale($validated['amount'], 2);
        $available = $balances->availableAdvance($invoice->vendor);
        $outstanding = $balances->invoiceOutstanding($invoice);
        $maximum = bccomp($available, $outstanding, 2) === -1 ? $available : $outstanding;

        if (bccomp($amount, $maximum, 2) === 1) {
            throw ValidationException::withMessages([
                'amount' => 'Adjustment cannot exceed the available advance or the invoice outstanding.',
            ]);
        }

        DB::transaction(function () use ($request, $invoice, $amount, $validated, $balances): void {
            VendorAdvanceAdjustment::query()->create([
                'vendor_id' => $invoice->vendor_id,
                'purchase_invoice_id' => $invoice->id,
                'adjustment_date' => now()->toDateString(),
                'amount' => $amount,
                'remarks' => $validated['remarks'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $balances->refreshInvoice($invoice);
        });

        return back()->with('success', 'Vendor advance applied.');
    }

    private function persist(Request $request, Project $project, ?PurchaseInvoice $invoice, PurchaseInvoiceLineService $lines): PurchaseInvoice
    {
        if ($invoice && ! $invoice->isEditable()) {
            throw ValidationException::withMessages([
                'invoice' => 'Only draft or rejected invoices without payments can be edited.',
            ]);
        }

        $lineMode = $request->exists('items');
        $validated = $request->validate([
            'purchase_order_id' => ['required', 'integer'],
            'invoice_number' => ['required', 'string', 'max:100'],
            'invoice_date' => ['required', 'date'],
            'invoice_amount' => [$lineMode ? 'nullable' : 'required', 'numeric', 'gte:0'],
            'tax_amount' => ['nullable', 'numeric', 'gte:0'],
            'tax_percentage' => ['nullable', 'numeric', Rule::in(PurchaseInvoice::TAX_RATES)],
            'round_off' => ['nullable', 'numeric', 'between:-100,100'],
            'round_off_mode' => ['nullable', Rule::in(['auto', 'manual'])],
            'due_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'invoice_file' => array_merge(['nullable'], StoredUpload::rules(StoredUpload::DOCUMENTS, 10240)),
            'items' => [$lineMode ? 'present' : 'nullable', 'array'],
            'items.*.purchase_order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['nullable'],
        ]);

        $order = $this->orderForProject($project, (int) $validated['purchase_order_id']);

        if (! $order) {
            throw ValidationException::withMessages([
                'purchase_order_id' => 'Select a purchase order for this project.',
            ]);
        }

        if (! $order->vendor_id) {
            $order = $this->attachVendor($request, $order);
        }

        $invoiceNumber = trim($validated['invoice_number']);
        $this->ensureUniqueInvoiceNumber($order->vendor_id, $invoiceNumber, $invoice?->id);

        $amount = Money::scale($validated['invoice_amount'] ?? 0, 2) ?? '0.00';
        $taxPercentage = isset($validated['tax_percentage']) ? Money::scale($validated['tax_percentage'], 2) : null;
        $tax = $taxPercentage !== null && ! $lineMode
            ? Money::percentage($amount, $taxPercentage)
            : (Money::scale($validated['tax_amount'] ?? 0, 2) ?? '0.00');
        $roundOffMode = $validated['round_off_mode'] ?? 'auto';
        $manualRoundOff = $validated['round_off'] ?? null;
        $beforeRound = Money::add($amount, $tax);
        $canRoundOff = app(Access::class)->allows($request->user(), 'invoices.round_off')
            || app(Access::class)->isSuperAdmin($request->user());

        if (! $canRoundOff) {
            $roundOffMode = 'auto';
            $manualRoundOff = null;
        } elseif ($roundOffMode === 'manual' || ($lineMode && $manualRoundOff !== null && bccomp((string) (Money::scale($manualRoundOff, 2) ?? '0.00'), '0.00', 2) !== 0)) {
            // Manual / non-zero line round-off requires invoices.round_off (already checked).
        }

        $roundOff = $lineMode
            ? ($canRoundOff ? (Money::scale($manualRoundOff ?? 0, 2) ?? '0.00') : '0.00')
            : $this->resolveRoundOff($beforeRound, $roundOffMode, $canRoundOff ? $manualRoundOff : null);

        $attributes = [
            'project_id' => $project->id,
            'purchase_order_id' => $order->id,
            'vendor_id' => $order->vendor_id,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $validated['invoice_date'],
            'invoice_amount' => $amount,
            'tax_percentage' => $taxPercentage,
            'tax_amount' => $tax,
            'round_off' => $roundOff,
            'total_amount' => Money::add($beforeRound, $roundOff),
            'due_date' => $validated['due_date'] ?? null,
            'remarks' => $validated['remarks'] ?? null,
        ];

        if ($request->file('invoice_file')) {
            $attributes['invoice_file_path'] = StoredUpload::store(
                $request->file('invoice_file'),
                'projects/'.$project->id.'/invoices',
            );

            if ($invoice?->invoice_file_path) {
                Storage::disk('local')->delete($invoice->invoice_file_path);
            }
        }

        try {
            $saved = DB::transaction(function () use ($invoice, $attributes, $request, $lineMode, $lines, $order, $taxPercentage, $roundOffMode, $manualRoundOff): PurchaseInvoice {
                if ($invoice) {
                    $invoice->update($attributes);
                    $saved = $invoice;
                } else {
                    $saved = PurchaseInvoice::query()->create([
                        ...$attributes,
                        'status' => PurchaseInvoice::STATUS_RECORDED,
                        'approval_status' => PurchaseInvoice::APPROVAL_DRAFT,
                        'created_by' => $request->user()->id,
                    ]);
                }

                if ($lineMode) {
                    $saved->update($lines->sync(
                        $saved,
                        $order,
                        $request->input('items', []),
                        $taxPercentage,
                        $roundOffMode,
                        $manualRoundOff,
                    ));
                }

                return $saved;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'invoice_number' => 'This invoice number is already recorded for this vendor.',
            ]);
        }

        return $saved->fresh();
    }

    private function resolveRoundOff(string $beforeRound, string $mode, mixed $manual): string
    {
        if ($mode === 'manual') {
            $value = Money::scale($manual ?? 0, 2) ?? '0.00';

            if (bccomp(ltrim($value, '-'), '100.00', 2) === 1) {
                throw ValidationException::withMessages([
                    'round_off' => 'Round off cannot exceed ₹100.00.',
                ]);
            }

            return $value;
        }

        $nearest = Money::scale(round((float) $beforeRound), 2) ?? $beforeRound;

        return Money::sub($nearest, $beforeRound);
    }

    private function ensureUniqueInvoiceNumber(int $vendorId, string $invoiceNumber, ?int $exceptId = null): void
    {
        $exists = PurchaseInvoice::query()
            ->where('vendor_id', $vendorId)
            ->where('invoice_number', $invoiceNumber)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'invoice_number' => 'This invoice number is already recorded for this vendor.',
            ]);
        }
    }

    private function attachVendor(Request $request, PurchaseOrder $order): PurchaseOrder
    {
        $vendor = Vendor::query()->find($request->integer('vendor_id'));

        if (! $vendor || ! $vendor->isActive()) {
            throw ValidationException::withMessages([
                'vendor_id' => 'This purchase order has no vendor. Select an active vendor to record the invoice.',
            ]);
        }

        $order->update([
            'vendor_id' => $vendor->id,
            'supplier_name' => $vendor->name,
            'supplier_address' => $vendor->address,
            'supplier_phone' => $vendor->phone,
            'supplier_email' => $vendor->email,
            'gst_number' => $vendor->gst_number,
            'vendor_name_snapshot' => $vendor->name,
            'vendor_address_snapshot' => $vendor->address,
            'vendor_phone_snapshot' => $vendor->phone,
            'vendor_email_snapshot' => $vendor->email,
            'vendor_gst_snapshot' => $vendor->gst_number,
        ]);

        return $order->fresh('vendor');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function vendorOptions(): array
    {
        return Vendor::query()
            ->where('status', Vendor::ACTIVE)
            ->orderBy('name')
            ->get(['id', 'vendor_code', 'name'])
            ->map(fn (Vendor $vendor) => [
                'id' => $vendor->id,
                'vendor_code' => $vendor->vendor_code,
                'name' => $vendor->name,
            ])
            ->all();
    }

    private function orderForProject(Project $project, ?int $orderId, bool $approved = false): ?PurchaseOrder
    {
        if (! $orderId) {
            return null;
        }

        $order = PurchaseOrder::query()->with(['vendor', 'items'])->findOrFail($orderId);
        abort_unless($order->project_id === $project->id, 404);

        if ($approved && $order->status !== DocumentStatus::APPROVED) {
            return null;
        }

        return $order;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function orderOptions(Project $project): array
    {
        return $project->purchaseOrders()->with('vendor')->where('status', DocumentStatus::APPROVED)->orderByDesc('po_date')->get()->map(fn (PurchaseOrder $order) => [
            'id' => $order->id,
            'po_number' => $order->po_number,
            'vendor' => $order->vendor?->name ?? $order->supplier_name,
            'grand_total' => (string) $order->grand_total,
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function orderCard(PurchaseOrder $order, PurchaseInvoiceLineService $lines, ?int $exceptInvoiceId = null): array
    {
        return [
            'id' => $order->id,
            'po_number' => $order->po_number,
            'vendor_id' => $order->vendor_id,
            'vendor' => $order->vendor?->name ?? $order->vendor_name_snapshot ?? $order->supplier_name,
            'grand_total' => (string) $order->grand_total,
            'gst_percentage' => (string) ($order->gst_percentage ?? '0.00'),
            'cgst_percentage' => (string) ($order->cgst_percentage ?? '0.00'),
            'sgst_percentage' => (string) ($order->sgst_percentage ?? '0.00'),
            'lines' => $lines->linesFor($order, $exceptInvoiceId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(PurchaseInvoice $invoice, VendorBalanceService $balances): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'vendor' => $invoice->vendor?->name,
            'vendor_id' => $invoice->vendor_id,
            'po_number' => $invoice->purchaseOrder?->po_number,
            'purchase_order_id' => $invoice->purchase_order_id,
            'po_grand_total' => (string) $invoice->purchaseOrder?->grand_total,
            'invoice_amount' => (string) $invoice->invoice_amount,
            'tax_percentage' => $invoice->tax_percentage === null ? null : (string) $invoice->tax_percentage,
            'tax_amount' => (string) $invoice->tax_amount,
            'round_off' => (string) ($invoice->round_off ?? '0.00'),
            'total_amount' => (string) $invoice->total_amount,
            'paid' => $balances->directInvoicePayments($invoice),
            'advance_applied' => $balances->advanceApplied($invoice),
            'outstanding' => $balances->invoiceOutstanding($invoice),
            'status' => $invoice->status,
            'approval_status' => $invoice->approval_status,
            'approved_by' => $invoice->approver?->name,
            'approved_at' => $invoice->approved_at?->toDateTimeString(),
            'rejected_by' => $invoice->rejectedBy?->name,
            'rejected_at' => $invoice->rejected_at?->toDateTimeString(),
            'rejection_reason' => $invoice->rejection_reason,
            'remarks' => $invoice->remarks,
            'has_attachment' => $invoice->invoice_file_path !== null,
            'items' => $invoice->items->map(fn (PurchaseInvoiceItem $item) => [
                'id' => $item->id,
                'service_code' => $item->service_code,
                'description' => $item->description,
                'unit' => $item->unit,
                'po_quantity' => (string) $item->po_quantity,
                'previously_invoiced' => (string) $item->previously_invoiced,
                'quantity' => (string) $item->quantity,
                'rate' => (string) $item->rate,
                'amount' => (string) $item->amount,
                'taxable_amount' => (string) $item->taxable_amount,
                'cgst_amount' => (string) $item->cgst_amount,
                'sgst_amount' => (string) $item->sgst_amount,
                'line_total' => (string) $item->line_total,
            ])->values()->all(),
        ];
    }

    private function renderPdf(Project $project, PurchaseInvoice $invoice, VendorBalanceService $balances, DocumentPdf $pdf, bool $download): HttpResponse
    {
        $this->ensureInvoice($project, $invoice);
        $invoice->load(['vendor', 'purchaseOrder', 'items']);
        $payload = $this->invoicePayload($invoice, $balances);

        $data = [
            'title' => 'PURCHASE INVOICE',
            'number' => $payload['invoice_number'],
            'revision' => null,
            'dateLabel' => 'Invoice date',
            'date' => PdfFormat::date($payload['invoice_date']),
            'status' => $invoice->isApproved() ? $payload['status'] : $payload['approval_status'],
            'party' => filled($payload['vendor']) ? [
                'label' => 'Vendor',
                'name' => $payload['vendor'],
                'lines' => [],
            ] : null,
            'relatedLabel' => filled($payload['po_number']) ? 'Purchase Order' : null,
            'relatedValue' => $payload['po_number'] ?? '',
            'footerNote' => 'Purchase invoice',
            'projectName' => $project->project_name,
            'projectCode' => $project->project_code,
            'invoice' => $payload,
        ];

        return $download
            ? $pdf->download('pdf.purchase-invoice', $data, $payload['invoice_number'].'.pdf')
            : $pdf->inline('pdf.purchase-invoice', $data, $payload['invoice_number'].'.pdf');
    }

    private function ensureInvoice(Project $project, PurchaseInvoice $invoice): void
    {
        abort_unless($invoice->project_id === $project->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function projectCard(Project $project): array
    {
        return [
            'id' => $project->id,
            'project_name' => $project->project_name,
            'project_code' => $project->project_code,
        ];
    }
}
