<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceItem;
use App\Models\ClientPayment;
use App\Models\Project;
use App\Services\Access;
use App\Services\ClientInvoiceService;
use App\Services\DocumentPdf;
use App\Support\DocumentStatus;
use App\Support\Listing;
use App\Support\PdfFormat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ClientInvoiceController extends Controller
{
    public function __construct(
        private Access $access,
        private ClientInvoiceService $invoices,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($this->access->allows($request->user(), 'client_invoices.view'), 403);
        $project = $this->routeProject($request);

        $filters = Listing::filters($request);
        $search = $filters['search'];
        $approval = $request->string('approval_status')->toString();
        $payment = $request->string('payment_status')->toString();
        $clientId = $request->integer('client_id') ?: null;

        $query = ClientInvoice::query()
            ->with(['client:id,name,client_code', 'project:id,project_name,project_code', 'payments'])
            ->when($project, fn ($q) => $q->where('project_id', $project->id))
            ->when(! $project, fn ($q) => $q->whereIn('project_id', Project::query()->visibleTo($request->user())->select('id')))
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when(in_array($approval, DocumentStatus::all(), true), fn ($q) => $q->where('approval_status', $approval))
            ->when(in_array($payment, [ClientInvoice::PAYMENT_UNPAID, ClientInvoice::PAYMENT_PARTIAL, ClientInvoice::PAYMENT_PAID], true), fn ($q) => $q->where('payment_status', $payment))
            ->orderByDesc('invoice_date')
            ->orderByDesc('id');
        Listing::applySearch($query, $search, [
            'invoice_number', 'invoice_date', 'client_name', 'reference_number', 'grand_total', 'approval_status', 'payment_status',
            'client.name', 'client.client_code', 'project.project_code', 'project.project_name',
        ]);

        $page = Listing::paginate($query, $request);

        return Inertia::render('ClientInvoices/Index', [
            'project' => $project ? $this->projectCard($project) : null,
            'invoices' => $page->through(fn (ClientInvoice $row) => $this->listRow($row)),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name', 'client_code']),
            'filters' => [
                ...$filters,
                'approval_status' => in_array($approval, DocumentStatus::all(), true) ? $approval : '',
                'payment_status' => $payment,
                'client_id' => $clientId,
            ],
            'canCreate' => $project !== null && $this->access->allows($request->user(), 'client_invoices.create'),
        ]);
    }

    public function create(Request $request, Project $project): Response
    {
        abort_unless($this->access->allows($request->user(), 'client_invoices.create'), 403);

        return Inertia::render('ClientInvoices/Form', $this->formProps($request, $project));
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        abort_unless($this->access->allows($request->user(), 'client_invoices.create'), 403);

        $invoice = $this->invoices->persist($request, $project, $request->user());

        return redirect()
            ->route('projects.client-invoices.show', [$project, $invoice])
            ->with('success', 'Client invoice saved as a draft.');
    }

    public function show(Request $request, Project $project, ClientInvoice $clientInvoice): Response
    {
        $invoice = $clientInvoice;
        abort_unless($this->access->allows($request->user(), 'client_invoices.view'), 403);
        $this->ensureInvoice($project, $invoice);
        $invoice->load(['client', 'items', 'payments.creator', 'approver', 'rejectedBy', 'creator']);

        return Inertia::render('ClientInvoices/Show', [
            'project' => $this->projectCard($project),
            'invoice' => $this->detailPayload($invoice),
            'paymentModes' => ClientPayment::MODES,
            'abilities' => $this->abilities($request, $invoice),
        ]);
    }

    public function edit(Request $request, Project $project, ClientInvoice $clientInvoice): Response
    {
        $invoice = $clientInvoice;
        abort_unless($this->access->allows($request->user(), 'client_invoices.edit'), 403);
        $this->ensureInvoice($project, $invoice);
        abort_unless($invoice->isEditable(), 403);

        return Inertia::render('ClientInvoices/Form', $this->formProps($request, $project, $invoice));
    }

    public function update(Request $request, Project $project, ClientInvoice $clientInvoice): RedirectResponse
    {
        $invoice = $clientInvoice;
        abort_unless($this->access->allows($request->user(), 'client_invoices.edit'), 403);
        $this->ensureInvoice($project, $invoice);

        $this->invoices->persist($request, $project, $request->user(), $invoice);

        return redirect()
            ->route('projects.client-invoices.show', [$project, $invoice])
            ->with('success', 'Client invoice updated.');
    }

    public function destroy(Request $request, Project $project, ClientInvoice $clientInvoice): RedirectResponse
    {
        $invoice = $clientInvoice;
        abort_unless($this->access->allows($request->user(), 'client_invoices.delete'), 403);
        $this->ensureInvoice($project, $invoice);
        abort_unless($invoice->isEditable() && ! $invoice->isApproved(), 403);

        $invoice->delete();

        return redirect()
            ->route('projects.client-invoices.index', $project)
            ->with('success', 'Client invoice deleted.');
    }

    public function submit(Request $request, Project $project, ClientInvoice $clientInvoice): RedirectResponse
    {
        $invoice = $clientInvoice;
        abort_unless($this->access->allows($request->user(), 'client_invoices.submit'), 403);
        $this->ensureInvoice($project, $invoice);
        $this->invoices->submit($invoice);

        return back()->with('success', 'Client invoice submitted for approval.');
    }

    public function approve(Request $request, Project $project, ClientInvoice $clientInvoice): RedirectResponse
    {
        $invoice = $clientInvoice;
        abort_unless($this->access->allows($request->user(), 'client_invoices.approve'), 403);
        $this->ensureInvoice($project, $invoice);
        $this->invoices->approve($invoice, $request->user());

        return back()->with('success', 'Client invoice approved.');
    }

    public function reject(Request $request, Project $project, ClientInvoice $clientInvoice): RedirectResponse
    {
        $invoice = $clientInvoice;
        abort_unless($this->access->allows($request->user(), 'client_invoices.reject'), 403);
        $this->ensureInvoice($project, $invoice);
        $validated = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $this->invoices->reject($invoice, $request->user(), $validated['rejection_reason']);

        return back()->with('success', 'Client invoice rejected.');
    }

    public function storePayment(Request $request, Project $project, ClientInvoice $clientInvoice): RedirectResponse
    {
        $invoice = $clientInvoice;
        abort_unless($this->access->allows($request->user(), 'client_invoices.record_payment'), 403);
        $this->ensureInvoice($project, $invoice);
        $this->invoices->recordPayment($request, $invoice, $request->user());

        return back()->with('success', 'Payment recorded.');
    }

    public function pdf(Request $request, Project $project, ClientInvoice $clientInvoice, DocumentPdf $pdf): HttpResponse
    {
        return $this->renderPdf($request, $project, $clientInvoice, $pdf, false);
    }

    public function downloadPdf(Request $request, Project $project, ClientInvoice $clientInvoice, DocumentPdf $pdf): HttpResponse
    {
        return $this->renderPdf($request, $project, $clientInvoice, $pdf, true);
    }

    private function renderPdf(Request $request, Project $project, ClientInvoice $clientInvoice, DocumentPdf $pdf, bool $download): HttpResponse
    {
        $invoice = $clientInvoice;
        abort_unless($this->access->allows($request->user(), 'client_invoices.print'), 403);
        $this->ensureInvoice($project, $invoice);
        $invoice->load(['client', 'items', 'payments', 'approver', 'project']);
        $payload = $this->detailPayload($invoice);

        $data = [
            'title' => 'TAX INVOICE',
            'number' => $payload['invoice_number'],
            'dateLabel' => 'Invoice date',
            'date' => PdfFormat::date($payload['invoice_date']),
            'status' => $invoice->isApproved() ? $payload['payment_status'] : $payload['approval_status'],
            'party' => [
                'label' => 'Bill To',
                'name' => $payload['client_name'],
                'lines' => array_values(array_filter([
                    ['text' => $payload['client_address']],
                    $payload['client_gst'] ? ['label' => 'GSTIN', 'text' => $payload['client_gst']] : null,
                    $payload['client_pan'] ? ['label' => 'PAN', 'text' => $payload['client_pan']] : null,
                ])),
            ],
            'projectName' => $project->project_name,
            'projectCode' => $project->project_code,
            'relatedLabel' => 'Project',
            'relatedValue' => $project->project_code,
            'invoice' => $payload,
        ];

        return $download
            ? $pdf->download('pdf.client-invoice', $data, $payload['invoice_number'])
            : $pdf->inline('pdf.client-invoice', $data, $payload['invoice_number']);
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(Request $request, Project $project, ?ClientInvoice $clientInvoice = null): array
    {
        $invoice = $clientInvoice;
        $invoice?->load('items');

        return [
            'project' => $this->projectCard($project),
            'invoice' => $invoice ? $this->formPayload($invoice) : null,
            'clients' => Client::query()->where('status', Client::ACTIVE)
                ->when($invoice, fn ($q) => $q->orWhereKey($invoice->client_id))
                ->orderBy('name')
                ->get(['id', 'name', 'client_code', 'address', 'city', 'state', 'pincode', 'gst_number', 'pan_number', 'payment_terms']),
            'billableItems' => $this->invoices->billableItems($project, $invoice?->id),
            'taxRates' => ClientInvoice::TAX_RATES,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formPayload(ClientInvoice $clientInvoice): array
    {
        $invoice = $clientInvoice;

        return [
            'id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'place_of_supply' => $invoice->place_of_supply,
            'reference_number' => $invoice->reference_number,
            'description' => $invoice->description,
            'payment_terms' => $invoice->payment_terms,
            'remarks' => $invoice->remarks,
            'gst_percentage' => rtrim(rtrim((string) $invoice->gst_percentage, '0'), '.') ?: '0',
            'round_off' => (string) $invoice->round_off,
            'items' => $invoice->items->map(fn (ClientInvoiceItem $item) => [
                'boq_item_id' => $item->boq_item_id,
                'description' => $item->description,
                'unit' => $item->unit,
                'quantity' => (string) $item->quantity,
                'rate' => (string) $item->rate,
                'discount_percentage' => (string) $item->discount_percentage,
                'remarks' => $item->remarks,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listRow(ClientInvoice $clientInvoice): array
    {
        $invoice = $clientInvoice;
        $outstanding = $invoice->isApproved() ? $invoice->outstanding() : (string) $invoice->grand_total;

        return [
            'id' => $invoice->id,
            'project_id' => $invoice->project_id,
            'project_code' => $invoice->project?->project_code,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'client' => $invoice->client?->name ?? $invoice->client_name,
            'grand_total' => (string) $invoice->grand_total,
            'paid' => $invoice->isApproved() ? $invoice->paidTotal() : '0.00',
            'outstanding' => $invoice->isApproved() ? $outstanding : (string) $invoice->grand_total,
            'approval_status' => $invoice->approval_status,
            'payment_status' => $invoice->payment_status,
            'overdue' => $invoice->isOverdue(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(ClientInvoice $clientInvoice): array
    {
        $invoice = $clientInvoice;
        $invoice->loadMissing(['items', 'payments', 'approver', 'rejectedBy', 'creator']);

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'client_id' => $invoice->client_id,
            'client_name' => $invoice->client_name,
            'client_address' => $invoice->client_address,
            'client_gst' => $invoice->client_gst,
            'client_pan' => $invoice->client_pan,
            'place_of_supply' => $invoice->place_of_supply,
            'reference_number' => $invoice->reference_number,
            'description' => $invoice->description,
            'payment_terms' => $invoice->payment_terms,
            'remarks' => $invoice->remarks,
            'gst_percentage' => (string) $invoice->gst_percentage,
            'cgst_percentage' => (string) $invoice->cgst_percentage,
            'sgst_percentage' => (string) $invoice->sgst_percentage,
            'subtotal' => (string) $invoice->subtotal,
            'discount_total' => (string) $invoice->discount_total,
            'taxable_value' => (string) $invoice->taxable_value,
            'cgst_amount' => (string) $invoice->cgst_amount,
            'sgst_amount' => (string) $invoice->sgst_amount,
            'gst_amount' => (string) $invoice->gst_amount,
            'round_off' => (string) $invoice->round_off,
            'grand_total' => (string) $invoice->grand_total,
            'paid' => $invoice->paidTotal(),
            'outstanding' => $invoice->outstanding(),
            'approval_status' => $invoice->approval_status,
            'payment_status' => $invoice->payment_status,
            'overdue' => $invoice->isOverdue(),
            'created_by' => $invoice->creator?->name,
            'approved_by' => $invoice->approver?->name,
            'approved_at' => $invoice->approved_at?->toDateTimeString(),
            'rejected_by' => $invoice->rejectedBy?->name,
            'rejected_at' => $invoice->rejected_at?->toDateTimeString(),
            'rejection_reason' => $invoice->rejection_reason,
            'items' => $invoice->items->map(fn (ClientInvoiceItem $item) => [
                'id' => $item->id,
                'item_number' => $item->item_number,
                'description' => $item->description,
                'unit' => $item->unit,
                'quantity' => (string) $item->quantity,
                'rate' => (string) $item->rate,
                'amount' => (string) $item->amount,
                'discount_percentage' => (string) $item->discount_percentage,
                'discount_amount' => (string) $item->discount_amount,
                'taxable_amount' => (string) $item->taxable_amount,
                'cgst_amount' => (string) $item->cgst_amount,
                'sgst_amount' => (string) $item->sgst_amount,
                'line_total' => (string) $item->line_total,
            ])->values()->all(),
            'payments' => $invoice->payments->map(fn (ClientPayment $payment) => [
                'id' => $payment->id,
                'payment_date' => $payment->payment_date?->toDateString(),
                'payment_mode' => $payment->payment_mode,
                'amount' => (string) $payment->amount,
                'reference_number' => $payment->reference_number,
                'remarks' => $payment->remarks,
                'created_by' => $payment->creator?->name,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(Request $request, ClientInvoice $clientInvoice): array
    {
        $invoice = $clientInvoice;
        $user = $request->user();

        return [
            'edit' => $invoice->isEditable() && $this->access->allows($user, 'client_invoices.edit'),
            'delete' => $invoice->isEditable() && $this->access->allows($user, 'client_invoices.delete'),
            'submit' => in_array($invoice->approval_status, [DocumentStatus::DRAFT, DocumentStatus::REJECTED], true)
                && $this->access->allows($user, 'client_invoices.submit'),
            'approve' => $invoice->approval_status === DocumentStatus::SUBMITTED
                && $this->access->allows($user, 'client_invoices.approve'),
            'reject' => $invoice->approval_status === DocumentStatus::SUBMITTED
                && $this->access->allows($user, 'client_invoices.reject'),
            'pay' => $invoice->isApproved()
                && bccomp($invoice->outstanding(), '0.00', 2) === 1
                && $this->access->allows($user, 'client_invoices.record_payment'),
            'print' => $this->access->allows($user, 'client_invoices.print'),
        ];
    }

    private function routeProject(Request $request): ?Project
    {
        $project = $request->route('project');
        if ($project instanceof Project) {
            return $project;
        }

        return $project ? Project::query()->find($project) : null;
    }

    private function ensureInvoice(Project $project, ClientInvoice $clientInvoice): void
    {
        abort_unless((int) $clientInvoice->project_id === (int) $project->id, 404);
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
