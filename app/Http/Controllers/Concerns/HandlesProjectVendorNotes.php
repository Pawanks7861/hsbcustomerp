<?php

namespace App\Http\Controllers\Concerns;

use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Access;
use App\Services\DocumentPdf;
use App\Services\VendorNoteService;
use App\Support\DocumentStatus;
use App\Support\Listing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

trait HandlesProjectVendorNotes
{
    abstract protected function notePermissionPrefix(): string;

    abstract protected function noteModelClass(): string;

    abstract protected function noteNumberPrefix(): string;

    abstract protected function inertiaFolder(): string;

    abstract protected function pdfView(): string;

    abstract protected function routePrefix(): string;

    public function index(Project $project, Access $access, Request $request): Response
    {
        $this->authorizeView($request->user(), $access);
        $filters = Listing::filters($request);
        $query = $project->{$this->relationName()}()
            ->with(['vendor', 'purchaseOrder', 'purchaseInvoice'])
            ->latest('date')
            ->orderByDesc('id');
        Listing::applySearch($query, $filters['search'], [
            'number', 'date', 'total_amount', 'status', 'vendor.name', 'vendor.vendor_code',
            'purchaseOrder.po_number', 'purchaseInvoice.invoice_number',
        ]);

        return Inertia::render($this->inertiaFolder().'/Index', [
            'project' => $this->projectCard($project),
            'notes' => Listing::paginate($query, $request)->through(fn (DebitNote|CreditNote $note) => $this->listRow($note)),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request, Project $project, Access $access): Response
    {
        abort_unless($access->allows($request->user(), $this->notePermissionPrefix().'.create'), 403);

        return Inertia::render($this->inertiaFolder().'/Form', [
            'project' => $this->projectCard($project),
            'note' => null,
            'vendors' => $this->vendorOptions(),
            'purchaseOrders' => $this->orderOptions($project),
            'invoices' => $this->invoiceOptions($project),
        ]);
    }

    public function store(Request $request, Project $project, VendorNoteService $notes, Access $access): RedirectResponse
    {
        abort_unless($access->allows($request->user(), $this->notePermissionPrefix().'.create'), 403);
        $note = $notes->store($request, $project, $this->noteModelClass(), $this->noteNumberPrefix());

        return redirect()->route($this->routePrefix().'.show', [$project, $note])->with('success', 'Note saved.');
    }

    public function show(Project $project, DebitNote|CreditNote $note, Access $access, Request $request): Response
    {
        $this->authorizeView($request->user(), $access);
        $this->ensureNote($project, $note);
        $note->load(['vendor', 'purchaseOrder.items', 'purchaseInvoice', 'creator', 'approver']);
        if ($note instanceof DebitNote) {
            $note->load('items');
        }

        return Inertia::render($this->inertiaFolder().'/Show', [
            'project' => $this->projectCard($project),
            'note' => $this->detailPayload($note),
            'abilities' => $this->abilities($request->user(), $note, $access),
        ]);
    }

    public function edit(Request $request, Project $project, DebitNote|CreditNote $note, Access $access): Response
    {
        abort_unless($access->allows($request->user(), $this->notePermissionPrefix().'.edit'), 403);
        $this->ensureNote($project, $note);
        $note->load(['purchaseOrder.items']);
        if ($note instanceof DebitNote) {
            $note->load('items');
        }

        return Inertia::render($this->inertiaFolder().'/Form', [
            'project' => $this->projectCard($project),
            'note' => $this->detailPayload($note),
            'vendors' => $this->vendorOptions(),
            'purchaseOrders' => $this->orderOptions($project),
            'invoices' => $this->invoiceOptions($project),
        ]);
    }

    public function update(Request $request, Project $project, DebitNote|CreditNote $note, VendorNoteService $notes, Access $access): RedirectResponse
    {
        abort_unless($access->allows($request->user(), $this->notePermissionPrefix().'.edit'), 403);
        $this->ensureNote($project, $note);
        $notes->update($request, $project, $note, $this->noteModelClass());

        return redirect()->route($this->routePrefix().'.show', [$project, $note])->with('success', 'Note updated.');
    }

    public function destroy(Request $request, Project $project, DebitNote|CreditNote $note, Access $access): RedirectResponse
    {
        abort_unless($access->allows($request->user(), $this->notePermissionPrefix().'.edit'), 403);
        $this->ensureNote($project, $note);

        if ($note->status === DocumentStatus::APPROVED) {
            return back()->with('error', 'Approved notes cannot be deleted.');
        }

        $note->delete();

        return redirect()->route($this->routePrefix().'.index', $project)->with('success', 'Note deleted.');
    }

    public function submit(Project $project, DebitNote|CreditNote $note, VendorNoteService $notes, Access $access, Request $request): RedirectResponse
    {
        abort_unless($access->allows($request->user(), $this->notePermissionPrefix().'.submit'), 403);
        $this->ensureNote($project, $note);
        $notes->submit($note);

        return back()->with('success', 'Note submitted for approval.');
    }

    public function approve(Request $request, Project $project, DebitNote|CreditNote $note, VendorNoteService $notes, Access $access): RedirectResponse
    {
        abort_unless($access->allows($request->user(), $this->notePermissionPrefix().'.approve'), 403);
        $this->ensureNote($project, $note);
        $notes->approve($note, $request->user());

        return back()->with('success', 'Note approved.');
    }

    public function reject(Request $request, Project $project, DebitNote|CreditNote $note, VendorNoteService $notes, Access $access): RedirectResponse
    {
        abort_unless($access->allows($request->user(), $this->notePermissionPrefix().'.reject'), 403);
        $this->ensureNote($project, $note);
        $notes->reject($note, $request->user(), $request->string('rejection_reason')->toString() ?: null);

        return back()->with('success', 'Note rejected.');
    }

    public function pdf(Project $project, DebitNote|CreditNote $note, DocumentPdf $pdf, Access $access, Request $request): HttpResponse
    {
        return $this->renderPdf($project, $note, $pdf, $access, $request, false);
    }

    public function downloadPdf(Project $project, DebitNote|CreditNote $note, DocumentPdf $pdf, Access $access, Request $request): HttpResponse
    {
        return $this->renderPdf($project, $note, $pdf, $access, $request, true);
    }

    private function renderPdf(Project $project, DebitNote|CreditNote $note, DocumentPdf $pdf, Access $access, Request $request, bool $download): HttpResponse
    {
        abort_unless($access->allows($request->user(), $this->notePermissionPrefix().'.print'), 403);
        $this->ensureNote($project, $note);
        $note->load(['vendor', 'purchaseOrder', 'purchaseInvoice', 'project']);
        if ($note instanceof DebitNote) {
            $note->load('items');
        }

        $payload = [
            'title' => $this->inertiaFolder() === 'DebitNotes' ? 'Debit Note' : 'Credit Note',
            'number' => $note->number,
            'status' => $note->status,
            'note' => $this->detailPayload($note),
        ];

        return $download
            ? $pdf->download($this->pdfView(), $payload, $note->number)
            : $pdf->inline($this->pdfView(), $payload, $note->number);
    }

    private function authorizeView(?User $user, Access $access): void
    {
        abort_unless($access->allows($user, $this->notePermissionPrefix().'.view'), 403);
    }

    private function ensureNote(Project $project, DebitNote|CreditNote $note): void
    {
        abort_unless((int) $note->project_id === (int) $project->id, 404);
    }

    private function relationName(): string
    {
        return $this->noteModelClass() === DebitNote::class ? 'debitNotes' : 'creditNotes';
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

    /**
     * @return list<array<string, mixed>>
     */
    private function vendorOptions(): array
    {
        return Vendor::query()->where('status', Vendor::ACTIVE)->orderBy('name')->get(['id', 'name', 'vendor_code'])
            ->map(fn (Vendor $vendor) => ['id' => $vendor->id, 'name' => $vendor->name, 'vendor_code' => $vendor->vendor_code])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orderOptions(Project $project): array
    {
        return $project->purchaseOrders()->with(['vendor', 'items'])->latest('po_date')->get()
            ->map(fn (PurchaseOrder $order) => [
                'id' => $order->id,
                'po_number' => $order->po_number,
                'vendor_id' => $order->vendor_id,
                'vendor_name' => $order->vendor?->name,
                'items' => $order->items->map(fn ($item) => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'unit' => $item->unit,
                    'rate' => (string) $item->rate,
                ])->values()->all(),
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invoiceOptions(Project $project): array
    {
        return $project->purchaseInvoices()->with('vendor')->latest('invoice_date')->get()
            ->map(fn (PurchaseInvoice $invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'vendor_id' => $invoice->vendor_id,
                'vendor_name' => $invoice->vendor?->name,
            ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function listRow(DebitNote|CreditNote $note): array
    {
        return [
            'id' => $note->id,
            'number' => $note->number,
            'date' => $note->date?->toDateString(),
            'vendor' => $note->vendor?->name,
            'total_amount' => (string) $note->total_amount,
            'status' => $note->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(DebitNote|CreditNote $note): array
    {
        $payload = [
            'id' => $note->id,
            'number' => $note->number,
            'date' => $note->date?->toDateString(),
            'vendor_id' => $note->vendor_id,
            'vendor_name' => $note->vendor?->name,
            'purchase_order_id' => $note->purchase_order_id,
            'po_number' => $note->purchaseOrder?->po_number,
            'purchase_invoice_id' => $note->purchase_invoice_id,
            'invoice_number' => $note->purchaseInvoice?->invoice_number,
            'reason' => $note->reason,
            'remarks' => $note->remarks,
            'status' => $note->status,
            'subtotal' => (string) $note->subtotal,
            'tax_amount' => (string) $note->tax_amount,
            'round_off' => (string) $note->round_off,
            'total_amount' => (string) $note->total_amount,
            'created_by' => $note->creator?->name,
            'approved_by' => $note->approver?->name,
            'rejection_reason' => $note->rejection_reason,
        ];

        if ($note instanceof CreditNote) {
            $payload['vendor_reference'] = $note->vendor_reference;
        }

        if ($note instanceof DebitNote) {
            $payload['items'] = $note->items->map(fn ($item) => [
                'id' => $item->id,
                'purchase_order_item_id' => $item->purchase_order_item_id,
                'description' => $item->description,
                'unit' => $item->unit,
                'quantity' => (string) $item->quantity,
                'rate' => (string) $item->rate,
                'amount' => (string) $item->amount,
                'remarks' => $item->remarks,
            ])->values()->all();
        }

        return $payload;
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(?User $user, DebitNote|CreditNote $note, Access $access): array
    {
        $prefix = $this->notePermissionPrefix();
        $editable = in_array($note->status, [DocumentStatus::DRAFT, DocumentStatus::REJECTED], true);

        return [
            'edit' => $editable && $access->allows($user, $prefix.'.edit'),
            'submit' => $editable && $access->allows($user, $prefix.'.submit'),
            'approve' => $note->status === DocumentStatus::SUBMITTED && $access->allows($user, $prefix.'.approve'),
            'reject' => $note->status === DocumentStatus::SUBMITTED && $access->allows($user, $prefix.'.reject'),
            'print' => $access->allows($user, $prefix.'.print'),
        ];
    }
}
