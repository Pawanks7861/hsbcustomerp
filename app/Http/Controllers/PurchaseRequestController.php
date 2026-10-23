<?php

namespace App\Http\Controllers;

use App\Http\Requests\MultiPurchaseRequestRequest;
use App\Http\Requests\PurchaseRequestItemRequest;
use App\Http\Requests\RejectDocumentRequest;
use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\CompanySetting;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Services\BoqProcurementQuantityService;
use App\Services\DocumentNumber;
use App\Services\DocumentPdf;
use App\Services\ProjectBoqCatalog;
use App\Services\PurchaseRequestLineComposer;
use App\Support\PdfFormat;
use App\Support\DocumentStatus;
use App\Support\Money;
use App\Support\PurchaseLines;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PurchaseRequestController extends Controller
{
    public function __construct(
        private BoqProcurementQuantityService $quantities,
        private ProjectBoqCatalog $catalog,
        private PurchaseRequestLineComposer $lines,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        $status = $request->string('status')->toString();
        $search = trim($request->string('search')->toString());

        $records = $project->purchaseRequests()
            ->with(['boqItem', 'requestedBy', 'items'])
            ->when(in_array($status, DocumentStatus::all(), true), fn ($query) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('pr_number', 'like', $like)
                        ->orWhereHas('items', function ($items) use ($like) {
                            $items->where('name', 'like', $like)
                                ->orWhere('description', 'like', $like);
                        })
                        ->orWhereHas('boqItem', function ($item) use ($like) {
                            $item->where('description', 'like', $like)
                                ->orWhere('item_number', 'like', $like);
                        });
                });
            })
            ->orderByDesc('request_date')
            ->orderByDesc('id')
            ->get();

        $summaries = $this->quantities->summariesForItems($records->map(fn (PurchaseRequest $record) => $record->boqItem));

        return Inertia::render('PurchaseRequests/Index', [
            'project' => $this->projectCard($project),
            'purchaseRequests' => $records->map(fn (PurchaseRequest $record) => $this->listPayload($record, $summaries[$record->boq_item_id] ?? null))->values(),
            'filters' => [
                'status' => in_array($status, DocumentStatus::all(), true) ? $status : '',
                'search' => $search,
            ],
        ]);
    }

    public function create(Project $project, Boq $boq, BoqItem $item): Response
    {
        $this->ensureItem($project, $boq, $item);
        abort_unless($item->isProcurable(), 404);

        return Inertia::render('PurchaseRequests/Form', [
            'project' => $this->projectCard($project),
            'boq' => $this->boqCard($boq),
            'item' => $this->itemCard($item),
            'purchaseRequest' => null,
            'quantityControl' => $this->quantities->getSummary($item),
        ]);
    }

    public function store(PurchaseRequestItemRequest $request, Project $project, Boq $boq, BoqItem $item): RedirectResponse
    {
        $this->ensureItem($project, $boq, $item);
        abort_unless($item->isProcurable(), 404);

        $record = $this->persistNew($request, $project, $boq, $item);

        return redirect()
            ->route('projects.purchase-requests.show', [$project, $record])
            ->with('success', 'Purchase request created.');
    }

    public function show(Request $request, Project $project, PurchaseRequest $purchaseRequest): Response
    {
        $this->ensureRequest($project, $purchaseRequest);
        $purchaseRequest->load(['boq', 'boqItem', 'items.boqItem', 'requestedBy', 'approvedBy', 'rejectedBy', 'overQuantityApprovedBy', 'purchaseOrders']);

        return Inertia::render('PurchaseRequests/Show', [
            'project' => $this->projectCard($project),
            'company' => CompanySetting::current()?->document(),
            'purchaseRequest' => $this->detailPayload($purchaseRequest),
            'abilities' => $this->abilities($request, $purchaseRequest),
        ]);
    }

    public function pdf(Project $project, PurchaseRequest $purchaseRequest, DocumentPdf $pdf): HttpResponse
    {
        return $this->renderPdf($project, $purchaseRequest, $pdf, false);
    }

    public function downloadPdf(Project $project, PurchaseRequest $purchaseRequest, DocumentPdf $pdf): HttpResponse
    {
        return $this->renderPdf($project, $purchaseRequest, $pdf, true);
    }

    public function edit(Project $project, PurchaseRequest $purchaseRequest): Response
    {
        $this->ensureRequest($project, $purchaseRequest);
        $this->authorize('update', $purchaseRequest);
        $purchaseRequest->load(['boq', 'boqItem', 'items.boqItem']);

        return Inertia::render('PurchaseRequests/MultiForm', [
            'project' => $this->projectCard($project),
            'boqItems' => $this->catalog->selectable($project),
            'purchaseRequest' => $this->formPayload($purchaseRequest),
        ]);
    }

    public function update(PurchaseRequestItemRequest $request, Project $project, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->ensureRequest($project, $purchaseRequest);
        $this->authorize('update', $purchaseRequest);

        $this->lines->save($purchaseRequest, [
            'request_date' => $request->validated('request_date'),
            'required_date' => $request->validated('required_date'),
            'description' => $request->validated('description'),
            'requested_boq_quantity' => $request->requestedBoqQuantity(),
        ], PurchaseLines::requestItems($request->validated('items')));

        return redirect()
            ->route('projects.purchase-requests.show', [$project, $purchaseRequest])
            ->with('success', 'Purchase request updated.');
    }

    public function destroy(Project $project, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->ensureRequest($project, $purchaseRequest);
        $this->authorize('delete', $purchaseRequest);
        $purchaseRequest->delete();

        return redirect()
            ->route('projects.purchase-requests.index', $project)
            ->with('success', 'Purchase request deleted.');
    }

    public function submit(Request $request, Project $project, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->ensureRequest($project, $purchaseRequest);
        $this->authorize('submit', $purchaseRequest);

        $this->quantities->submit($purchaseRequest);

        return back()->with('success', 'Purchase request submitted.');
    }

    public function approve(Request $request, Project $project, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->ensureRequest($project, $purchaseRequest);
        $this->authorize('approve', $purchaseRequest);

        $attributes = [
            'status' => DocumentStatus::APPROVED,
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ];

        if ($purchaseRequest->is_boq_quantity_exceeded) {
            $note = $request->validate([
                'over_quantity_approval_note' => ['required', 'string', 'max:5000'],
            ])['over_quantity_approval_note'];

            $attributes['over_quantity_approved_by'] = $request->user()->id;
            $attributes['over_quantity_approved_at'] = now();
            $attributes['over_quantity_approval_note'] = $note;
        }

        $purchaseRequest->update($attributes);

        return back()->with('success', 'Purchase request approved.');
    }

    public function reject(RejectDocumentRequest $request, Project $project, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->ensureRequest($project, $purchaseRequest);
        $this->authorize('reject', $purchaseRequest);

        $purchaseRequest->update([
            'status' => DocumentStatus::REJECTED,
            'rejected_at' => now(),
            'rejected_by' => $request->user()->id,
            'rejection_reason' => $request->validated('rejection_reason'),
        ]);

        return back()->with('success', 'Purchase request rejected.');
    }

    public function reopen(Request $request, Project $project, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->ensureRequest($project, $purchaseRequest);
        $this->authorize('reopen', $purchaseRequest);

        $purchaseRequest->update([
            'status' => DocumentStatus::DRAFT,
        ]);

        return back()->with('success', 'Purchase request returned to draft.');
    }

    private function renderPdf(Project $project, PurchaseRequest $purchaseRequest, DocumentPdf $pdf, bool $download): HttpResponse
    {
        $this->ensureRequest($project, $purchaseRequest);
        $purchaseRequest->load(['items', 'requestedBy', 'approvedBy', 'rejectedBy', 'overQuantityApprovedBy']);
        $document = $this->detailPayload($purchaseRequest);
        unset($document['boq_title'], $document['boq_item'], $document['quantity_control']);
        $document['items'] = collect($document['items'] ?? [])->map(fn (array $line) => [
            'name' => $line['name'] ?? '',
            'description' => $line['description'] ?? '',
            'unit' => $line['unit'] ?? '',
            'quantity' => $line['quantity'] ?? '',
            'service_code' => $line['service_code'] ?? '',
            'remarks' => $line['remarks'] ?? '',
        ])->all();
        $document['over_quantity_approval_note'] = $purchaseRequest->over_quantity_approval_note;

        $data = [
            'title' => 'PURCHASE REQUEST',
            'number' => $document['pr_number'],
            'dateLabel' => 'Request date',
            'date' => PdfFormat::date($document['request_date'] ?? null),
            'status' => $document['status'],
            'footerNote' => 'Purchase request',
            'projectName' => $project->project_name,
            'projectCode' => $project->project_code,
            'document' => $document,
        ];

        $filename = $document['pr_number'].'.pdf';

        return $download
            ? $pdf->download('pdf.purchase-request', $data, $filename)
            : $pdf->inline('pdf.purchase-request', $data, $filename);
    }

    public function createMany(Project $project): Response
    {
        return Inertia::render('PurchaseRequests/MultiForm', [
            'project' => $this->projectCard($project),
            'boqItems' => $this->catalog->selectable($project),
            'purchaseRequest' => null,
        ]);
    }

    public function storeMany(MultiPurchaseRequestRequest $request, Project $project): RedirectResponse
    {
        $built = $this->lines->compose($project, $request->validated('items'));
        $exception = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $record = DB::transaction(function () use ($request, $project, $built) {
                    $payload = array_map(function (array $line): array {
                        unset($line['id']);

                        return $line;
                    }, $built['lines']);

                    $record = PurchaseRequest::query()->create([
                        'project_id' => $project->id,
                        'boq_id' => $built['item']?->boq_id,
                        'boq_item_id' => $built['item']?->id,
                        'pr_number' => DocumentNumber::next(PurchaseRequest::class, 'pr_number', 'PR'),
                        'request_date' => $request->validated('request_date'),
                        'required_date' => $request->validated('required_date'),
                        'requested_by' => $request->user()->id,
                        'description' => $request->validated('description'),
                        'requested_boq_quantity' => $built['lines'][0]['quantity'],
                        'status' => DocumentStatus::DRAFT,
                    ]);
                    $record->items()->createMany($payload);

                    return $record;
                });

                return redirect()
                    ->route('projects.purchase-requests.show', [$project, $record])
                    ->with('success', 'Purchase request created.');
            } catch (UniqueConstraintViolationException $caught) {
                $exception = $caught;
            }
        }

        throw $exception;
    }

    public function updateMany(MultiPurchaseRequestRequest $request, Project $project, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->ensureRequest($project, $purchaseRequest);
        $this->authorize('update', $purchaseRequest);
        $built = $this->lines->compose($project, $request->validated('items'));

        $this->lines->save($purchaseRequest, [
            'boq_id' => $built['item']?->boq_id,
            'boq_item_id' => $built['item']?->id,
            'request_date' => $request->validated('request_date'),
            'required_date' => $request->validated('required_date'),
            'description' => $request->validated('description'),
            'requested_boq_quantity' => $built['lines'][0]['quantity'],
        ], $built['lines']);

        return redirect()
            ->route('projects.purchase-requests.show', [$project, $purchaseRequest])
            ->with('success', 'Purchase request updated.');
    }

    private function persistNew(PurchaseRequestItemRequest $request, Project $project, Boq $boq, BoqItem $item): PurchaseRequest
    {
        $exception = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($request, $project, $boq, $item) {
                    $record = PurchaseRequest::query()->create([
                        'project_id' => $project->id,
                        'boq_id' => $boq->id,
                        'boq_item_id' => $item->id,
                        'pr_number' => DocumentNumber::next(PurchaseRequest::class, 'pr_number', 'PR'),
                        'request_date' => $request->validated('request_date'),
                        'required_date' => $request->validated('required_date'),
                        'requested_by' => $request->user()->id,
                        'description' => $request->validated('description'),
                        'requested_boq_quantity' => $request->requestedBoqQuantity(),
                        'status' => DocumentStatus::DRAFT,
                    ]);

                    $record->items()->createMany(PurchaseLines::requestItems($request->validated('items')));

                    return $record;
                });
            } catch (UniqueConstraintViolationException $caught) {
                $exception = $caught;
            }
        }

        throw $exception;
    }

    private function ensureItem(Project $project, Boq $boq, BoqItem $item): void
    {
        abort_unless($boq->project_id === $project->id, 404);
        abort_unless($item->boq_id === $boq->id, 404);
        abort_unless((int) $item->boq_revision_id === (int) $boq->procurementRevisionId(), 404);
    }

    private function ensureRequest(Project $project, PurchaseRequest $purchaseRequest): void
    {
        abort_unless($purchaseRequest->project_id === $project->id, 404);

        if ($purchaseRequest->boq_id) {
            abort_unless($purchaseRequest->boq()->where('project_id', $project->id)->exists(), 404);
        }

        if ($purchaseRequest->boq_item_id) {
            abort_unless($purchaseRequest->boqItem()->where('boq_id', $purchaseRequest->boq_id)->exists(), 404);
        }
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(Request $request, PurchaseRequest $purchaseRequest): array
    {
        $user = $request->user();

        return [
            'update' => $user->can('update', $purchaseRequest),
            'delete' => $user->can('delete', $purchaseRequest),
            'submit' => $user->can('submit', $purchaseRequest),
            'approve' => $user->can('approve', $purchaseRequest),
            'reject' => $user->can('reject', $purchaseRequest),
            'reopen' => $user->can('reopen', $purchaseRequest),
            'createPurchaseOrder' => $user->can('createPurchaseOrder', $purchaseRequest),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $summary
     * @return array<string, mixed>
     */
    private function listPayload(PurchaseRequest $record, ?array $summary = null): array
    {
        return [
            'id' => $record->id,
            'pr_number' => $record->pr_number,
            'boq_item' => $this->itemSummary($record),
            'request_date' => $record->request_date?->toDateString(),
            'required_date' => $record->required_date?->toDateString(),
            'requested_by' => $record->requestedBy?->name,
            'estimated_total' => $record->estimatedTotal(),
            'status' => $record->status,
            'quantity_control' => $this->quantities->displayFor($record, $summary),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(PurchaseRequest $record): array
    {
        return [
            ...$this->formPayload($record),
            'pr_number' => $record->pr_number,
            'boq_title' => $record->boq?->title,
            'boq_item' => $this->itemSummary($record),
            'status' => $record->status,
            'requested_by' => $record->requestedBy?->name,
            'estimated_total' => $record->estimatedTotal(),
            'submitted_at' => $record->submitted_at?->toDateTimeString(),
            'approved_at' => $record->approved_at?->toDateTimeString(),
            'approved_by' => $record->approvedBy?->name,
            'rejected_at' => $record->rejected_at?->toDateTimeString(),
            'rejected_by' => $record->rejectedBy?->name,
            'rejection_reason' => $record->rejection_reason,
            'requested_boq_quantity' => (string) $record->requested_boq_quantity,
            'is_boq_quantity_exceeded' => (bool) $record->is_boq_quantity_exceeded,
            'item_count' => $record->items->count(),
            'purchase_orders' => $record->purchaseOrders->map(fn ($order) => [
                'id' => $order->id,
                'po_number' => $order->po_number,
                'status' => $order->status,
            ])->values()->all(),
            'quantity_control' => $this->quantityControlPayload($record),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formPayload(PurchaseRequest $record): array
    {
        return [
            'id' => $record->id,
            'request_date' => $record->request_date?->toDateString(),
            'required_date' => $record->required_date?->toDateString(),
            'description' => $record->description,
            'requested_boq_quantity' => (string) $record->requested_boq_quantity,
            'items' => $record->items->map(fn (PurchaseRequestItem $item) => $this->itemPayload($record, $item))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemPayload(PurchaseRequest $record, PurchaseRequestItem $item): array
    {
        $payload = [
            'id' => $item->id,
            'boq_item_id' => $item->boq_item_id,
            'boq_label' => trim(($item->boqItem?->item_number ?? '').' '.($item->boqItem?->description ?? '')),
            'name' => $item->name ?: $item->description,
            'description' => $item->description,
            'service_code' => $item->service_code ?? '',
            'unit' => $item->unit ?? '',
            'quantity' => (string) $item->quantity,
            'estimated_rate' => $item->estimated_rate === null ? '' : (string) $item->estimated_rate,
            'estimated_amount' => $item->estimated_amount === null ? null : (string) $item->estimated_amount,
            'remarks' => $item->remarks ?? '',
            'quantity_check' => null,
        ];

        if ($item->boq_item_id === null) {
            return $payload;
        }

        $historical = in_array($record->status, [DocumentStatus::SUBMITTED, DocumentStatus::APPROVED, DocumentStatus::REJECTED], true)
            && $item->boq_quantity_at_submission !== null;

        if ($historical) {
            $previous = (string) $item->previous_requested_quantity;
            $requested = (string) $item->quantity;
            $payload['quantity_check'] = [
                'boq_quantity' => (string) $item->boq_quantity_at_submission,
                'previous_requested_quantity' => $previous,
                'requested_quantity' => $requested,
                'cumulative_quantity' => Money::add($previous, $requested, 3),
                'remaining_before_request' => bcsub((string) $item->boq_quantity_at_submission, $previous, 3),
                'excess_quantity' => (string) $item->excess_quantity,
                'is_exceeded' => $record->status !== DocumentStatus::REJECTED && (bool) $item->is_boq_quantity_exceeded,
                'unit' => $item->unit,
            ];

            return $payload;
        }

        if ($item->boqItem) {
            $payload['quantity_check'] = $this->quantities->checkRequest($item->boqItem, (string) $item->quantity, $record);
        }

        return $payload;
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
     * @return array<string, mixed>
     */
    private function boqCard(Boq $boq): array
    {
        return [
            'id' => $boq->id,
            'title' => $boq->title,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemCard(BoqItem $item): array
    {
        return [
            'id' => $item->id,
            'item_number' => $item->item_number,
            'service_code' => $item->service_code,
            'description' => $item->description,
            'unit' => $item->unit,
            'quantity' => $item->quantity === null ? null : (string) $item->quantity,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemSummary(PurchaseRequest $record): string
    {
        $names = $record->items
            ->map(fn (PurchaseRequestItem $item) => trim((string) ($item->name ?: $item->description)))
            ->filter()
            ->values();

        if ($names->isNotEmpty()) {
            return $names->implode(', ');
        }

        return trim(($record->boqItem?->item_number ?? '').' '.($record->boqItem?->description ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function quantityControlPayload(PurchaseRequest $record): array
    {
        $item = $record->boqItem;

        if (! $item) {
            return [
                'has_quantity' => false,
                'historical' => false,
                'released' => false,
                'unit' => null,
                'over_quantity_approval_note' => $record->over_quantity_approval_note,
                'over_quantity_approved_by' => $record->overQuantityApprovedBy?->name,
                'over_quantity_approved_at' => $record->over_quantity_approved_at?->toDateTimeString(),
            ];
        }
        $useSnapshot = in_array($record->status, [DocumentStatus::SUBMITTED, DocumentStatus::APPROVED, DocumentStatus::REJECTED], true)
            && $record->boq_quantity_at_submission !== null;

        if ($useSnapshot) {
            $previous = (string) $record->previous_requested_quantity_at_submission;
            $requested = (string) $record->requested_boq_quantity;

            return [
                'has_quantity' => true,
                'historical' => true,
                'released' => $record->status === DocumentStatus::REJECTED,
                'unit' => $item?->unit,
                'boq_quantity' => (string) $record->boq_quantity_at_submission,
                'previous_requested_quantity' => $previous,
                'requested_quantity' => $requested,
                'cumulative_quantity' => Money::add($previous, $requested, 3),
                'remaining_before_request' => bcsub((string) $record->boq_quantity_at_submission, $previous, 3),
                'excess_quantity' => (string) $record->excess_quantity_at_submission,
                'is_exceeded' => (bool) $record->is_boq_quantity_exceeded,
                'over_quantity_approval_note' => $record->over_quantity_approval_note,
                'over_quantity_approved_by' => $record->overQuantityApprovedBy?->name,
                'over_quantity_approved_at' => $record->over_quantity_approved_at?->toDateTimeString(),
            ];
        }

        $check = $this->quantities->checkRequest($item, (string) ($record->requested_boq_quantity ?? '0'), $record);

        return [
            ...$check,
            'historical' => false,
            'released' => false,
            'over_quantity_approval_note' => null,
            'over_quantity_approved_by' => null,
            'over_quantity_approved_at' => null,
        ];
    }
}
