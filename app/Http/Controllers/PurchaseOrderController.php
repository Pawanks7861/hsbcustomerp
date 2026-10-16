<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseOrderItemRequest;
use App\Http\Requests\RejectDocumentRequest;
use App\Models\CompanySetting;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderRateAmendment;
use App\Models\PurchaseRequest;
use App\Models\Vendor;
use App\Services\Access;
use App\Services\ActivityLogger;
use App\Services\DocumentNumber;
use App\Services\DocumentPdf;
use App\Services\PoReceiptProgress;
use App\Services\PurchaseOrderRateAmendmentService;
use App\Support\DocumentStatus;
use App\Support\Money;
use App\Support\PdfFormat;
use App\Support\PurchaseLines;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private Access $access,
        private ActivityLogger $activity,
        private PurchaseOrderRateAmendmentService $rateAmendments,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        $status = $request->string('status')->toString();
        $search = trim($request->string('search')->toString());

        $records = $project->purchaseOrders()
            ->with(['boqItem', 'purchaseRequest'])
            ->when(in_array($status, DocumentStatus::all(), true), fn ($query) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('po_number', 'like', $like)
                        ->orWhere('supplier_name', 'like', $like)
                        ->orWhereHas('purchaseRequest', fn ($pr) => $pr->where('pr_number', 'like', $like))
                        ->orWhereHas('boqItem', function ($item) use ($like) {
                            $item->where('description', 'like', $like)
                                ->orWhere('item_number', 'like', $like);
                        });
                });
            })
            ->orderByDesc('po_date')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('PurchaseOrders/Index', [
            'project' => $this->projectCard($project),
            'purchaseOrders' => $records->map(fn (PurchaseOrder $record) => [
                'id' => $record->id,
                'po_number' => $record->po_number,
                'pr_number' => $record->purchaseRequest?->pr_number,
                'boq_item' => trim(($record->boqItem?->item_number ?? '').' '.($record->boqItem?->description ?? '')),
                'supplier_name' => $record->supplier_name,
                'po_date' => $record->po_date?->toDateString(),
                'grand_total' => (string) $record->grand_total,
                'status' => $record->status,
            ])->values(),
            'filters' => [
                'status' => in_array($status, DocumentStatus::all(), true) ? $status : '',
                'search' => $search,
            ],
        ]);
    }

    public function create(Request $request, Project $project, PurchaseRequest $purchaseRequest): Response
    {
        $this->ensureApprovedRequest($project, $purchaseRequest);
        $this->authorize('createPurchaseOrder', $purchaseRequest);
        $purchaseRequest->load(['items', 'boq', 'boqItem']);

        return Inertia::render('PurchaseOrders/Form', [
            'project' => $this->projectCard($project),
            'purchaseRequest' => [
                'id' => $purchaseRequest->id,
                'pr_number' => $purchaseRequest->pr_number,
            ],
            'boq' => [
                'id' => $purchaseRequest->boq->id,
                'title' => $purchaseRequest->boq->title,
            ],
            'item' => [
                'id' => $purchaseRequest->boqItem->id,
                'item_number' => $purchaseRequest->boqItem->item_number,
                'description' => $purchaseRequest->boqItem->description,
            ],
            'vendors' => $this->vendorOptions(),
            'purchaseOrder' => [
                'id' => null,
                'vendor_id' => '',
                'po_date' => now()->toDateString(),
                'supplier_name' => '',
                'supplier_address' => '',
                'supplier_phone' => '',
                'supplier_email' => '',
                'gst_number' => '',
                'delivery_date' => '',
                'delivery_address' => '',
                'place_of_supply' => '',
                'gst_percentage' => '0',
                'cgst_percentage' => '0',
                'sgst_percentage' => '0',
                'freight' => '0',
                'round_off' => '0',
                'subject' => '',
                'terms_conditions' => '',
                'remarks' => '',
                'items' => $this->linesFromRequest($purchaseRequest),
            ],
        ]);
    }

    public function store(PurchaseOrderItemRequest $request, Project $project, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->ensureApprovedRequest($project, $purchaseRequest);
        $this->authorize('createPurchaseOrder', $purchaseRequest);

        $record = $this->persistNew($request, $project, $purchaseRequest);

        return redirect()
            ->route('projects.purchase-orders.show', [$project, $record])
            ->with('success', 'Purchase order created.');
    }

    public function show(Request $request, Project $project, PurchaseOrder $purchaseOrder, PoReceiptProgress $progress): Response
    {
        $this->ensureOrder($project, $purchaseOrder);
        $purchaseOrder->load([
            'boq', 'boqItem', 'purchaseRequest', 'items', 'createdBy', 'approvedBy', 'rejectedBy',
            'rateAmendments.purchaseOrderItem', 'rateAmendments.requestedBy', 'rateAmendments.approvedBy', 'rateAmendments.rejectedBy',
        ]);

        return Inertia::render('PurchaseOrders/Show', [
            'project' => $this->projectCard($project),
            'company' => CompanySetting::current()?->document(),
            'purchaseOrder' => [
                ...$this->detailPayload($purchaseOrder),
                'receipt_status' => $progress->label($purchaseOrder),
                'pending_rate_amendments' => $this->pendingRateAmendmentsPayload($purchaseOrder),
            ],
            'abilities' => $this->abilities($request, $purchaseOrder),
        ]);
    }

    public function pdf(Project $project, PurchaseOrder $purchaseOrder, DocumentPdf $pdf): HttpResponse
    {
        return $this->renderPdf($project, $purchaseOrder, $pdf, false);
    }

    public function downloadPdf(Project $project, PurchaseOrder $purchaseOrder, DocumentPdf $pdf): HttpResponse
    {
        return $this->renderPdf($project, $purchaseOrder, $pdf, true);
    }

    public function edit(Project $project, PurchaseOrder $purchaseOrder): Response
    {
        $this->ensureOrder($project, $purchaseOrder);
        $this->authorize('update', $purchaseOrder);
        $purchaseOrder->load(['boq', 'boqItem', 'purchaseRequest', 'items']);

        return Inertia::render('PurchaseOrders/Form', [
            'project' => $this->projectCard($project),
            'purchaseRequest' => [
                'id' => $purchaseOrder->purchase_request_id,
                'pr_number' => $purchaseOrder->purchaseRequest?->pr_number,
            ],
            'boq' => [
                'id' => $purchaseOrder->boq->id,
                'title' => $purchaseOrder->boq->title,
            ],
            'item' => [
                'id' => $purchaseOrder->boqItem->id,
                'item_number' => $purchaseOrder->boqItem->item_number,
                'description' => $purchaseOrder->boqItem->description,
            ],
            'vendors' => $this->vendorOptions($purchaseOrder->vendor_id),
            'purchaseOrder' => [
                ...$this->formPayload($purchaseOrder),
                'abilities' => $this->abilities(request(), $purchaseOrder),
            ],
        ]);
    }

    public function update(PurchaseOrderItemRequest $request, Project $project, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->ensureOrder($project, $purchaseOrder);
        $this->authorize('update', $purchaseOrder);

        $canEditRates = $this->access->allows($request->user(), 'purchase_orders.edit_rates')
            || $this->access->allows($request->user(), 'purchase_orders.edit')
            || $request->user()->isSuperAdmin();

        DB::transaction(function () use ($request, $purchaseOrder, $canEditRates): void {
            $purchaseOrder->load('items');
            $commercial = $this->commercial($request);
            $source = $this->pricedSource($purchaseOrder, $request, $canEditRates);
            $priced = PurchaseLines::price($source, $request->validated('gst_percentage'), $commercial);
            $rateChanges = [];

            foreach ($purchaseOrder->items as $index => $item) {
                $line = $priced[$index];
                $previousRate = (string) $item->rate;
                $nextRate = Money::scale($line['rate'] ?? $item->rate, 2) ?? $previousRate;
                $nextAmount = Money::scale($line['amount'] ?? $item->amount, 2) ?? (string) $item->amount;

                if ($canEditRates && bccomp($previousRate, $nextRate, 2) !== 0) {
                    $rateChanges[] = [
                        'item_id' => $item->id,
                        'description' => $item->description,
                        'previous_rate' => $previousRate,
                        'new_rate' => $nextRate,
                    ];
                }

                $item->update([
                    'rate' => $canEditRates ? $nextRate : $item->rate,
                    'amount' => $canEditRates ? $nextAmount : $item->amount,
                    'hsn_sac' => $line['hsn_sac'],
                    'discount_percentage' => $line['discount_percentage'],
                    'discount_amount' => $line['discount_amount'],
                    'taxable_amount' => $line['taxable_amount'],
                    'cgst_amount' => $line['cgst_amount'],
                    'sgst_amount' => $line['sgst_amount'],
                    'line_total' => $line['line_total'],
                ]);
            }

            if ($rateChanges !== []) {
                $reason = trim((string) ($request->validated('rate_change_reason') ?? ''));
                if ($reason === '') {
                    throw ValidationException::withMessages([
                        'rate_change_reason' => 'Enter a reason when changing a purchase order rate.',
                    ]);
                }

                $this->activity->log($request->user()->id, 'purchase_order.rate_changed', $purchaseOrder, [
                    'po_number' => $purchaseOrder->po_number,
                    'reason' => $reason,
                    'changes' => $rateChanges,
                    'approval_status' => $purchaseOrder->status,
                ]);
            }

            $purchaseOrder->update([
                ...$this->header($request, $purchaseOrder),
                ...PurchaseLines::summarize($priced, $request->validated('gst_percentage'), $commercial),
            ]);
        });

        return redirect()
            ->route('projects.purchase-orders.show', [$project, $purchaseOrder])
            ->with('success', 'Purchase order updated.');
    }

    public function destroy(Project $project, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->ensureOrder($project, $purchaseOrder);
        $this->authorize('delete', $purchaseOrder);
        $purchaseOrder->delete();

        return redirect()
            ->route('projects.purchase-orders.index', $project)
            ->with('success', 'Purchase order deleted.');
    }

    public function submit(Request $request, Project $project, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->ensureOrder($project, $purchaseOrder);
        $this->authorize('submit', $purchaseOrder);

        $purchaseOrder->update([
            'status' => DocumentStatus::SUBMITTED,
            'submitted_at' => now(),
            'rejection_reason' => null,
            'rejected_at' => null,
            'rejected_by' => null,
        ]);

        return back()->with('success', 'Purchase order submitted.');
    }

    public function approve(Request $request, Project $project, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->ensureOrder($project, $purchaseOrder);
        $this->authorize('approve', $purchaseOrder);

        $purchaseOrder->update([
            'status' => DocumentStatus::APPROVED,
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Purchase order approved.');
    }

    public function reject(RejectDocumentRequest $request, Project $project, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->ensureOrder($project, $purchaseOrder);
        $this->authorize('reject', $purchaseOrder);

        $purchaseOrder->update([
            'status' => DocumentStatus::REJECTED,
            'rejected_at' => now(),
            'rejected_by' => $request->user()->id,
            'rejection_reason' => $request->validated('rejection_reason'),
        ]);

        return back()->with('success', 'Purchase order rejected.');
    }

    public function reopen(Request $request, Project $project, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->ensureOrder($project, $purchaseOrder);
        $this->authorize('reopen', $purchaseOrder);

        $purchaseOrder->update([
            'status' => DocumentStatus::DRAFT,
        ]);

        return back()->with('success', 'Purchase order returned to draft.');
    }

    public function storeRateAmendment(Request $request, Project $project, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->ensureOrder($project, $purchaseOrder);
        abort_unless($this->access->allows($request->user(), 'purchase_orders.request_rate_amendment'), 403);
        abort_unless($purchaseOrder->status === DocumentStatus::APPROVED, 403);

        $validated = $request->validate([
            'purchase_order_item_id' => ['required', 'integer'],
            'proposed_rate' => ['required'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $item = PurchaseOrderItem::query()->findOrFail($validated['purchase_order_item_id']);
        $this->rateAmendments->request(
            $purchaseOrder,
            $item,
            (string) $validated['proposed_rate'],
            (string) $validated['reason'],
            $request->user(),
        );

        return back()->with('success', 'Rate amendment requested.');
    }

    public function approveRateAmendment(
        Request $request,
        Project $project,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderRateAmendment $rateAmendment,
    ): RedirectResponse {
        $this->ensureOrder($project, $purchaseOrder);
        $this->ensureRateAmendment($purchaseOrder, $rateAmendment);
        abort_unless($this->access->allows($request->user(), 'purchase_orders.approve_rate_amendment'), 403);

        $validated = $request->validate([
            'decision_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->rateAmendments->approve(
            $rateAmendment,
            $request->user(),
            $validated['decision_comment'] ?? null,
        );

        return back()->with('success', 'Rate amendment approved.');
    }

    public function rejectRateAmendment(
        Request $request,
        Project $project,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderRateAmendment $rateAmendment,
    ): RedirectResponse {
        $this->ensureOrder($project, $purchaseOrder);
        $this->ensureRateAmendment($purchaseOrder, $rateAmendment);
        abort_unless($this->access->allows($request->user(), 'purchase_orders.reject_rate_amendment'), 403);

        $validated = $request->validate([
            'decision_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->rateAmendments->reject(
            $rateAmendment,
            $request->user(),
            $validated['decision_comment'] ?? null,
        );

        return back()->with('success', 'Rate amendment rejected.');
    }

    public function rateHistory(Request $request, Project $project, PurchaseOrder $purchaseOrder): Response
    {
        $this->ensureOrder($project, $purchaseOrder);
        abort_unless(
            $this->access->allows($request->user(), 'purchase_orders.view_rate_history')
            || $this->access->allows($request->user(), 'purchase_orders.view')
            || $request->user()->isSuperAdmin(),
            403
        );

        $purchaseOrder->load(['boq', 'boqItem', 'purchaseRequest']);

        return Inertia::render('PurchaseOrders/RateHistory', [
            'project' => $this->projectCard($project),
            'purchaseOrder' => [
                'id' => $purchaseOrder->id,
                'po_number' => $purchaseOrder->po_number,
                'status' => $purchaseOrder->status,
            ],
            'history' => $this->rateAmendments->rateHistory($purchaseOrder),
        ]);
    }

    private function renderPdf(Project $project, PurchaseOrder $purchaseOrder, DocumentPdf $pdf, bool $download): HttpResponse
    {
        $this->ensureOrder($project, $purchaseOrder);
        $purchaseOrder->load(['boq', 'boqItem.revision', 'purchaseRequest', 'items', 'createdBy', 'approvedBy', 'rejectedBy']);
        $document = $this->detailPayload($purchaseOrder);
        $revision = $purchaseOrder->boqItem?->revision?->revision_number;

        $data = [
            'title' => 'PURCHASE ORDER',
            'number' => $document['po_number'],
            'revision' => $revision,
            'subject' => $document['subject'] ?? '',
            'headerPartial' => 'pdf.partials.purchase-order-header',
            'dateLabel' => 'Order date',
            'date' => PdfFormat::date($document['po_date'] ?? null),
            'deliveryDate' => PdfFormat::date($document['delivery_date'] ?? null),
            'placeOfSupply' => $document['place_of_supply'] ?? '',
            'taxLabel' => ((float) ($document['cgst_percentage'] ?? 0) > 0 && (float) ($document['sgst_percentage'] ?? 0) > 0)
                ? 'Intra-state (CGST + SGST)'
                : '',
            'status' => $document['status'],
            'party' => $this->vendorParty($purchaseOrder),
            'relatedLabel' => 'Purchase Request',
            'relatedValue' => $document['pr_number'] ?? '',
            'footerNote' => 'Amounts as stored on the purchase order.',
            'projectName' => $project->project_name,
            'projectCode' => $project->project_code,
            'boqNumber' => $purchaseOrder->boq?->boq_number,
            'document' => $document,
            'vendor' => $this->vendorSnapshot($purchaseOrder),
        ];

        $filename = $document['po_number'].'.pdf';

        return $download
            ? $pdf->download('pdf.purchase-order', $data, $filename)
            : $pdf->inline('pdf.purchase-order', $data, $filename);
    }

    /**
     * @return array{name: ?string, address: ?string, city: ?string, state: ?string, pincode: ?string, phone: ?string, email: ?string, gst: ?string}
     */
    private function vendorSnapshot(PurchaseOrder $record): array
    {
        return [
            'name' => $record->vendor_name_snapshot ?: $record->supplier_name,
            'address' => $record->vendor_address_snapshot ?: $record->supplier_address,
            'city' => $record->vendor_city_snapshot,
            'state' => $record->vendor_state_snapshot,
            'pincode' => $record->vendor_pincode_snapshot,
            'phone' => $record->vendor_phone_snapshot ?: $record->supplier_phone,
            'email' => $record->vendor_email_snapshot ?: $record->supplier_email,
            'gst' => $record->vendor_gst_snapshot ?: $record->gst_number,
        ];
    }

    /**
     * @return array{label: string, name: ?string, lines: list<array{label?: string, text: string}>}
     */
    private function vendorParty(PurchaseOrder $record): array
    {
        $snapshot = $this->vendorSnapshot($record);
        $lines = [];

        foreach (preg_split('/\R/', (string) $snapshot['address']) ?: [] as $line) {
            $text = trim($line);
            if ($text !== '') {
                $lines[] = ['text' => $text];
            }
        }

        $place = collect([$snapshot['city'], $snapshot['state']])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->implode(' ');
        $pin = trim((string) $snapshot['pincode']);

        if ($place !== '' && $pin !== '') {
            $lines[] = ['text' => $place];
            $lines[] = ['text' => $pin];
        } elseif ($place !== '' || $pin !== '') {
            $lines[] = ['text' => trim($place.' '.$pin)];
        }

        $gst = trim((string) $snapshot['gst']);
        if ($gst !== '') {
            $lines[] = ['label' => 'GST Number', 'text' => $gst];
        }

        return [
            'label' => 'Vendor',
            'name' => $snapshot['name'],
            'lines' => $lines,
        ];
    }

    private function persistNew(PurchaseOrderItemRequest $request, Project $project, PurchaseRequest $purchaseRequest): PurchaseOrder
    {
        $purchaseRequest->loadMissing('items');

        if ($purchaseRequest->items->isEmpty()) {
            throw ValidationException::withMessages([
                'purchase_request' => 'The approved purchase request has no items to copy.',
            ]);
        }

        $exception = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($request, $project, $purchaseRequest) {
                    $commercial = $this->commercial($request);
                    $lines = PurchaseLines::price(
                        $this->withPostedCommercial($this->storedLines($purchaseRequest), $request),
                        $request->validated('gst_percentage'),
                        $commercial
                    );
                    $record = PurchaseOrder::query()->create([
                        'project_id' => $project->id,
                        'boq_id' => $purchaseRequest->boq_id,
                        'boq_item_id' => $purchaseRequest->boq_item_id,
                        'purchase_request_id' => $purchaseRequest->id,
                        'po_number' => DocumentNumber::next(PurchaseOrder::class, 'po_number', 'PO'),
                        ...$this->header($request),
                        ...PurchaseLines::summarize($lines, $request->validated('gst_percentage'), $commercial),
                        'status' => DocumentStatus::DRAFT,
                        'created_by' => $request->user()->id,
                    ]);
                    $record->items()->createMany($lines);

                    return $record;
                });
            } catch (UniqueConstraintViolationException $caught) {
                $exception = $caught;
            }
        }

        throw $exception;
    }

    /**
     * @return array<string, mixed>
     */
    private function header(PurchaseOrderItemRequest $request, ?PurchaseOrder $purchaseOrder = null): array
    {
        $vendor = Vendor::query()->findOrFail($request->validated('vendor_id'));

        if (! $vendor->isActive() && (int) $purchaseOrder?->vendor_id !== (int) $vendor->id) {
            throw ValidationException::withMessages([
                'vendor_id' => 'Select an active vendor.',
            ]);
        }

        return [
            'po_date' => $request->validated('po_date'),
            'vendor_id' => $vendor->id,
            'supplier_name' => $vendor->name,
            'supplier_address' => $vendor->address,
            'vendor_city_snapshot' => $vendor->city,
            'vendor_state_snapshot' => $vendor->state,
            'vendor_pincode_snapshot' => $vendor->pincode,
            'supplier_phone' => $vendor->phone,
            'supplier_email' => $vendor->email,
            'gst_number' => $vendor->gst_number,
            'vendor_name_snapshot' => $vendor->name,
            'vendor_address_snapshot' => $vendor->address,
            'vendor_phone_snapshot' => $vendor->phone,
            'vendor_email_snapshot' => $vendor->email,
            'vendor_gst_snapshot' => $vendor->gst_number,
            'subject' => $request->validated('subject'),
            'delivery_date' => $request->validated('delivery_date'),
            'delivery_address' => $request->validated('delivery_address'),
            'place_of_supply' => $request->validated('place_of_supply'),
            'terms_conditions' => $request->validated('terms_conditions'),
            'remarks' => $request->validated('remarks'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function vendorOptions(?int $includeId = null): array
    {
        return Vendor::query()
            ->where('status', Vendor::ACTIVE)
            ->when($includeId, fn ($query) => $query->orWhere('id', $includeId))
            ->orderBy('name')
            ->get(['id', 'vendor_code', 'name', 'address', 'phone', 'email', 'gst_number', 'status'])
            ->map(fn (Vendor $vendor) => [
                'id' => $vendor->id,
                'vendor_code' => $vendor->vendor_code,
                'name' => $vendor->name,
                'address' => $vendor->address,
                'phone' => $vendor->phone,
                'email' => $vendor->email,
                'gst_number' => $vendor->gst_number,
                'status' => $vendor->status,
            ])
            ->all();
    }

    private function ensureApprovedRequest(Project $project, PurchaseRequest $purchaseRequest): void
    {
        abort_unless($purchaseRequest->project_id === $project->id, 404);
        abort_unless($purchaseRequest->boq()->where('project_id', $project->id)->exists(), 404);
        abort_unless($purchaseRequest->boqItem()->where('boq_id', $purchaseRequest->boq_id)->exists(), 404);
        abort_unless($purchaseRequest->status === DocumentStatus::APPROVED, 403);
    }

    private function ensureOrder(Project $project, PurchaseOrder $purchaseOrder): void
    {
        abort_unless($purchaseOrder->project_id === $project->id, 404);
        abort_unless($purchaseOrder->boq()->where('project_id', $project->id)->exists(), 404);
        abort_unless($purchaseOrder->boqItem()->where('boq_id', $purchaseOrder->boq_id)->exists(), 404);
        abort_unless(
            $purchaseOrder->purchaseRequest()
                ->where('project_id', $project->id)
                ->where('boq_id', $purchaseOrder->boq_id)
                ->where('boq_item_id', $purchaseOrder->boq_item_id)
                ->exists(),
            404
        );
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(Request $request, PurchaseOrder $purchaseOrder): array
    {
        $user = $request->user();

        return [
            'update' => $user->can('update', $purchaseOrder),
            'delete' => $user->can('delete', $purchaseOrder),
            'submit' => $user->can('submit', $purchaseOrder),
            'approve' => $user->can('approve', $purchaseOrder),
            'reject' => $user->can('reject', $purchaseOrder),
            'reopen' => $user->can('reopen', $purchaseOrder),
            'edit_rates' => $purchaseOrder->status === DocumentStatus::DRAFT
                && $user->can('update', $purchaseOrder)
                && (
                    $this->access->allows($user, 'purchase_orders.edit_rates')
                    || $this->access->allows($user, 'purchase_orders.edit')
                    || $user->isSuperAdmin()
                ),
            'view_rates' => $this->access->allows($user, 'purchase_orders.view_rates')
                || $this->access->allows($user, 'purchase_orders.view')
                || $user->isSuperAdmin(),
            'request_rate_amendment' => $purchaseOrder->status === DocumentStatus::APPROVED
                && (
                    $this->access->allows($user, 'purchase_orders.request_rate_amendment')
                    || $user->isSuperAdmin()
                ),
            'approve_rate_amendment' => $this->access->allows($user, 'purchase_orders.approve_rate_amendment')
                || $user->isSuperAdmin(),
            'reject_rate_amendment' => $this->access->allows($user, 'purchase_orders.reject_rate_amendment')
                || $user->isSuperAdmin(),
            'view_rate_history' => $this->access->allows($user, 'purchase_orders.view_rate_history')
                || $this->access->allows($user, 'purchase_orders.view')
                || $user->isSuperAdmin(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingRateAmendmentsPayload(PurchaseOrder $purchaseOrder): array
    {
        return $purchaseOrder->rateAmendments
            ->where('status', PurchaseOrderRateAmendment::STATUS_PENDING)
            ->values()
            ->map(fn (PurchaseOrderRateAmendment $amendment) => [
                'id' => $amendment->id,
                'purchase_order_item_id' => $amendment->purchase_order_item_id,
                'item_description' => $amendment->purchaseOrderItem?->description,
                'previous_rate' => (string) $amendment->previous_rate,
                'proposed_rate' => (string) $amendment->proposed_rate,
                'previous_amount' => (string) $amendment->previous_amount,
                'proposed_amount' => (string) $amendment->proposed_amount,
                'reason' => $amendment->reason,
                'requested_by' => $amendment->requestedBy?->name,
                'requested_at' => $amendment->requested_at?->toDateTimeString(),
            ])
            ->all();
    }

    private function ensureRateAmendment(PurchaseOrder $purchaseOrder, PurchaseOrderRateAmendment $rateAmendment): void
    {
        abort_unless((int) $rateAmendment->purchase_order_id === (int) $purchaseOrder->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(PurchaseOrder $record): array
    {
        return [
            ...$this->formPayload($record),
            'po_number' => $record->po_number,
            'pr_number' => $record->purchaseRequest?->pr_number,
            'purchase_request_id' => $record->purchase_request_id,
            'requested_boq_quantity' => $record->purchaseRequest?->requested_boq_quantity === null
                ? null
                : (string) $record->purchaseRequest->requested_boq_quantity,
            'boq_unit' => $record->boqItem?->unit,
            'boq_title' => $record->boq?->title,
            'boq_item' => trim(($record->boqItem?->item_number ?? '').' '.($record->boqItem?->description ?? '')),
            'status' => $record->status,
            'created_by' => $record->createdBy?->name,
            'submitted_at' => $record->submitted_at?->toDateTimeString(),
            'approved_at' => $record->approved_at?->toDateTimeString(),
            'approved_by' => $record->approvedBy?->name,
            'rejected_at' => $record->rejected_at?->toDateTimeString(),
            'rejected_by' => $record->rejectedBy?->name,
            'rejection_reason' => $record->rejection_reason,
            'subtotal' => (string) $record->subtotal,
            'discount_total' => (string) $record->discount_total,
            'taxable_value' => (string) $record->taxable_value,
            'cgst_amount' => (string) $record->cgst_amount,
            'sgst_amount' => (string) $record->sgst_amount,
            'gst_amount' => (string) $record->gst_amount,
            'grand_total' => (string) $record->grand_total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formPayload(PurchaseOrder $record): array
    {
        return [
            'id' => $record->id,
            'vendor_id' => $record->vendor_id,
            'po_date' => $record->po_date?->toDateString(),
            'subject' => $record->subject ?? '',
            'supplier_name' => $record->vendor_name_snapshot ?: $record->supplier_name,
            'supplier_address' => $record->supplier_address ?? '',
            'supplier_phone' => $record->supplier_phone ?? '',
            'supplier_email' => $record->supplier_email ?? '',
            'gst_number' => $record->gst_number ?? '',
            'delivery_date' => $record->delivery_date?->toDateString() ?? '',
            'delivery_address' => $record->delivery_address ?? '',
            'place_of_supply' => $record->place_of_supply ?? '',
            'gst_percentage' => (string) $record->gst_percentage,
            'cgst_percentage' => (string) $record->cgst_percentage,
            'sgst_percentage' => (string) $record->sgst_percentage,
            'freight' => (string) $record->freight,
            'round_off' => (string) $record->round_off,
            'terms_conditions' => $record->terms_conditions ?? '',
            'remarks' => $record->remarks ?? '',
            'items' => $record->items->map(fn (PurchaseOrderItem $item) => [
                'id' => $item->id,
                'service_code' => $item->service_code ?? '',
                'description' => $item->description,
                'hsn_sac' => $item->hsn_sac ?? '',
                'unit' => $item->unit ?? '',
                'quantity' => (string) $item->quantity,
                'rate' => (string) $item->rate,
                'amount' => (string) $item->amount,
                'discount_percentage' => (string) $item->discount_percentage,
                'discount_amount' => (string) $item->discount_amount,
                'taxable_amount' => (string) $item->taxable_amount,
                'cgst_amount' => (string) $item->cgst_amount,
                'sgst_amount' => (string) $item->sgst_amount,
                'line_total' => (string) $item->line_total,
                'remarks' => $item->remarks ?? '',
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<int, array<string, mixed>>
     */
    private function linesFromRequest(PurchaseRequest $purchaseRequest): array
    {
        return collect($this->storedLines($purchaseRequest))->map(fn (array $line) => [
            'service_code' => $line['service_code'] ?? '',
            'description' => $line['description'],
            'hsn_sac' => '',
            'unit' => $line['unit'] ?? '',
            'quantity' => (string) $line['quantity'],
            'rate' => (string) $line['rate'],
            'amount' => (string) $line['amount'],
            'discount_percentage' => '0',
            'remarks' => $line['remarks'] ?? '',
        ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storedLines(PurchaseRequest $purchaseRequest): array
    {
        $purchaseRequest->loadMissing(['items.boqItem', 'boqItem']);

        return $purchaseRequest->items->map(function ($line) use ($purchaseRequest): array {
            $quantity = Money::scale($line->quantity, 3);
            $rate = Money::scale($line->estimated_rate, 2);
            if ($rate === null) {
                $boqItem = $line->boqItem ?? $purchaseRequest->boqItem;
                $rate = Money::scale($boqItem?->rate, 2) ?? '0.00';
            }

            return [
                'boq_item_id' => $line->boq_item_id,
                'service_code' => $line->service_code,
                'description' => $line->description,
                'unit' => $line->unit,
                'quantity' => $quantity,
                'rate' => $rate,
                'amount' => Money::itemAmount($quantity, $rate) ?? '0.00',
                'remarks' => $line->remarks,
            ];
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function commercial(PurchaseOrderItemRequest $request): array
    {
        $validated = $request->validated();
        $commercial = [
            'freight' => $validated['freight'] ?? 0,
            'round_off' => $validated['round_off'] ?? 0,
        ];

        if (array_key_exists('cgst_percentage', $validated) || array_key_exists('sgst_percentage', $validated)) {
            if ($validated['cgst_percentage'] !== null || $validated['sgst_percentage'] !== null) {
                $commercial['cgst_percentage'] = $validated['cgst_percentage'] ?? 0;
                $commercial['sgst_percentage'] = $validated['sgst_percentage'] ?? 0;
            }
        }

        return $commercial;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pricedSource(PurchaseOrder $purchaseOrder, PurchaseOrderItemRequest $request, bool $canEditRates = false): array
    {
        return $this->withPostedCommercial($purchaseOrder->items->map(fn (PurchaseOrderItem $item) => [
            'quantity' => (string) $item->quantity,
            'rate' => (string) $item->rate,
            'amount' => (string) $item->amount,
            'hsn_sac' => $item->hsn_sac,
            'discount_percentage' => (string) $item->discount_percentage,
        ])->all(), $request, $canEditRates);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function withPostedCommercial(array $lines, PurchaseOrderItemRequest $request, bool $canEditRates = false): array
    {
        $posted = $request->validated('items') ?? [];

        foreach ($lines as $index => $line) {
            $extra = $posted[$index] ?? [];

            if ($canEditRates && is_array($extra) && array_key_exists('rate', $extra) && $extra['rate'] !== null && $extra['rate'] !== '') {
                $rate = Money::scale($extra['rate'], 2) ?? '0.00';
                $line['rate'] = $rate;
                $line['amount'] = Money::itemAmount($line['quantity'] ?? null, $rate) ?? '0.00';
            }

            if (is_array($extra) && array_key_exists('hsn_sac', $extra)) {
                $line['hsn_sac'] = $extra['hsn_sac'];
            }

            if (is_array($extra) && array_key_exists('discount_percentage', $extra) && $extra['discount_percentage'] !== null) {
                $line['discount_percentage'] = $extra['discount_percentage'];
            }

            $lines[$index] = $line;
        }

        return $lines;
    }

    private function projectCard(Project $project): array
    {
        return [
            'id' => $project->id,
            'project_name' => $project->project_name,
            'project_code' => $project->project_code,
        ];
    }
}
