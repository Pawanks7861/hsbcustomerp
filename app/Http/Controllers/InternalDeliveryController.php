<?php

namespace App\Http\Controllers;

use App\Models\IdnAttachment;
use App\Models\InternalDeliveryNote;
use App\Models\InternalDeliveryNoteItem;
use App\Models\InventoryLocation;
use App\Models\Project;
use App\Models\User;
use App\Services\Access;
use App\Services\DocumentPdf;
use App\Services\InternalDeliveryService;
use App\Services\InventoryStock;
use App\Support\Listing;
use App\Support\Money;
use App\Support\PdfFormat;
use App\Support\StoredUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InternalDeliveryController extends Controller
{
    public function __construct(
        private InternalDeliveryService $deliveries,
        private Access $access,
        private InventoryStock $stock,
    ) {}

    public function index(Request $request, ?Project $project = null): Response
    {
        $this->authorizeView($request->user());
        $filters = Listing::filters($request);
        $query = $this->visibleQuery($request->user())
            ->with(['sourceLocation', 'destinationLocation', 'sourceProject', 'destinationProject', 'requester', 'dispatcher', 'receiver', 'items'])
            ->when($project, fn ($query) => $query->where(function ($inner) use ($project): void {
                $inner->where('source_project_id', $project->id)->orWhere('destination_project_id', $project->id);
            }))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('source_location_id'), fn ($query) => $query->where('source_location_id', $request->integer('source_location_id')))
            ->when($request->filled('destination_location_id'), fn ($query) => $query->where('destination_location_id', $request->integer('destination_location_id')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('transfer_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('transfer_date', '<=', $request->date('to')))
            ->when($request->filled('requested_by'), fn ($query) => $query->where('requested_by', $request->integer('requested_by')))
            ->latest('transfer_date')
            ->orderByDesc('id');
        Listing::applySearch($query, $filters['search'], [
            'idn_number', 'transfer_date', 'status',
            'sourceLocation.name', 'destinationLocation.name', 'sourceProject.project_name', 'destinationProject.project_name',
            'requester.name', 'dispatcher.name', 'receiver.name',
        ]);

        return Inertia::render('Inventory/Transfers/Index', [
            'project' => $project ? $this->card($project) : null,
            'transfers' => Listing::paginate($query, $request)->through(fn (InternalDeliveryNote $note) => $this->row($note)),
            'filters' => [...$request->only(['status', 'source_location_id', 'destination_location_id', 'from', 'to', 'requested_by']), ...$filters],
            'locations' => $this->locationOptions($request->user(), false),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request, ?Project $project = null): Response
    {
        abort_unless($this->access->allows($request->user(), 'idn.create'), 403);

        return Inertia::render('Inventory/Transfers/Form', [
            'project' => $project ? $this->card($project) : null,
            'transfer' => null,
            'locations' => $this->locationOptions($request->user(), true),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->access->allows($request->user(), 'idn.create'), 403);
        $header = $this->validateHeader($request);
        $this->assertLocationAccess($request->user(), (int) $header['source_location_id'], (int) $header['destination_location_id']);
        $note = $this->deliveries->save(null, $header, $request->input('items', []), $request->user());
        $this->storeFiles($request, $note, null);

        return redirect()->route('inventory.transfers.show', $note)->with('success', 'Internal delivery note created.');
    }

    public function show(Request $request, InternalDeliveryNote $transfer): Response
    {
        $this->authorizeNote($request->user(), $transfer);
        $transfer->load([
            'items.material',
            'sourceLocation',
            'destinationLocation',
            'sourceProject',
            'destinationProject',
            'requester',
            'approver',
            'dispatcher',
            'receiver',
            'receipts.receiver',
            'receipts.items',
            'attachments',
        ]);

        return Inertia::render('Inventory/Transfers/Show', [
            'transfer' => $this->detail($transfer),
            'abilities' => $this->abilities($request->user(), $transfer),
        ]);
    }

    public function edit(Request $request, InternalDeliveryNote $transfer): Response
    {
        abort_unless($this->access->allows($request->user(), 'idn.edit') && $transfer->isEditable(), 403);
        $this->authorizeNote($request->user(), $transfer);
        $transfer->load('items.material');

        return Inertia::render('Inventory/Transfers/Form', [
            'project' => null,
            'transfer' => $this->detail($transfer),
            'locations' => $this->locationOptions($request->user(), true),
        ]);
    }

    public function update(Request $request, InternalDeliveryNote $transfer): RedirectResponse
    {
        abort_unless($this->access->allows($request->user(), 'idn.edit'), 403);
        $this->authorizeNote($request->user(), $transfer);
        $header = $this->validateHeader($request);
        $this->assertLocationAccess($request->user(), (int) $header['source_location_id'], (int) $header['destination_location_id']);
        $this->deliveries->save($transfer, $header, $request->input('items', []), $request->user());
        $this->storeFiles($request, $transfer, null);

        return redirect()->route('inventory.transfers.show', $transfer)->with('success', 'Internal delivery note updated.');
    }

    public function submit(Request $request, InternalDeliveryNote $transfer): RedirectResponse
    {
        abort_unless($this->access->allows($request->user(), 'idn.submit'), 403);
        $this->authorizeNote($request->user(), $transfer);
        $this->deliveries->submit($transfer, $request->user());

        return back()->with('success', 'Internal delivery note submitted.');
    }

    public function approve(Request $request, InternalDeliveryNote $transfer): RedirectResponse
    {
        $this->authorizeNote($request->user(), $transfer);
        $this->deliveries->approve($transfer, $request->user());

        return back()->with('success', 'Internal delivery note approved.');
    }

    public function reject(Request $request, InternalDeliveryNote $transfer): RedirectResponse
    {
        $this->authorizeNote($request->user(), $transfer);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];
        $this->deliveries->reject($transfer, $request->user(), $reason);

        return back()->with('success', 'Internal delivery note rejected.');
    }

    public function dispatch(Request $request, InternalDeliveryNote $transfer): RedirectResponse
    {
        $this->authorizeNote($request->user(), $transfer);
        $this->deliveries->dispatch($transfer, $request->user());

        return back()->with('success', 'Materials dispatched.');
    }

    public function receive(Request $request, InternalDeliveryNote $transfer): RedirectResponse
    {
        $this->authorizeNote($request->user(), $transfer, true);
        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.quantity_received' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity_damaged' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity_short' => ['nullable', 'numeric', 'min:0'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
            'images' => ['nullable', 'array'],
            'images.*' => StoredUpload::rules(StoredUpload::IMAGES, 5120),
        ]);
        $receipt = $this->deliveries->receive($transfer, $data['items'], $request->user(), $data['remarks'] ?? null);
        $this->storeFiles($request, $transfer, $receipt->id);

        return back()->with('success', 'Destination receipt recorded.');
    }

    public function cancel(Request $request, InternalDeliveryNote $transfer): RedirectResponse
    {
        $this->authorizeNote($request->user(), $transfer);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];
        $this->deliveries->cancel($transfer, $request->user(), $reason);

        return back()->with('success', 'Internal delivery note cancelled.');
    }

    public function pdf(InternalDeliveryNote $transfer, DocumentPdf $pdf): HttpResponse
    {
        return $this->renderPdf($transfer, $pdf, false);
    }

    public function downloadPdf(InternalDeliveryNote $transfer, DocumentPdf $pdf): HttpResponse
    {
        return $this->renderPdf($transfer, $pdf, true);
    }

    public function attachment(Request $request, InternalDeliveryNote $transfer, IdnAttachment $attachment): StreamedResponse
    {
        $this->authorizeNote($request->user(), $transfer);
        abort_unless($attachment->internal_delivery_note_id === $transfer->id, 404);
        abort_unless(filled($attachment->path) && Storage::disk('local')->exists($attachment->path), 404);

        return StoredUpload::serve(
            $attachment->path,
            $attachment->original_name,
            inlineIfSafe: true,
            expectedPrefix: 'idn/'.$transfer->id,
        );
    }

    private function renderPdf(InternalDeliveryNote $transfer, DocumentPdf $pdf, bool $download): HttpResponse
    {
        $user = request()->user();
        abort_unless($user && $this->access->allows($user, 'idn.print'), 403);
        $this->authorizeNote($user, $transfer);
        $transfer->load(['items.material', 'sourceLocation', 'destinationLocation', 'sourceProject', 'destinationProject', 'requester', 'approver', 'dispatcher', 'receiver']);
        $data = [
            'title' => 'INTERNAL DELIVERY NOTE',
            'number' => $transfer->idn_number,
            'revision' => null,
            'dateLabel' => 'Transfer date',
            'date' => PdfFormat::date($transfer->transfer_date),
            'status' => $transfer->status,
            'party' => [
                'label' => 'Destination',
                'name' => $transfer->destinationLocation?->name,
                'lines' => array_values(array_filter([
                    $transfer->destinationProject ? ['text' => $transfer->destinationProject->project_name] : null,
                    $transfer->destinationLocation?->address ? ['text' => $transfer->destinationLocation->address] : null,
                ])),
            ],
            'relatedLabel' => 'Source',
            'relatedValue' => $transfer->sourceLocation?->name,
            'footerNote' => 'Internal delivery note',
            'transfer' => $transfer,
        ];

        return $download
            ? $pdf->download('pdf.internal-delivery', $data, $transfer->idn_number.'.pdf')
            : $pdf->inline('pdf.internal-delivery', $data, $transfer->idn_number.'.pdf');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateHeader(Request $request): array
    {
        return $request->validate([
            'transfer_date' => ['required', 'date'],
            'source_location_id' => ['required', 'integer', Rule::exists('inventory_locations', 'id')],
            'destination_location_id' => ['required', 'integer', 'different:source_location_id', Rule::exists('inventory_locations', 'id')],
            'expected_arrival_date' => ['nullable', 'date'],
            'vehicle_number' => ['nullable', 'string', 'max:50'],
            'driver_name' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.material_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => StoredUpload::rules(StoredUpload::DOCUMENTS, 5120),
        ]);
    }

    private function storeFiles(Request $request, InternalDeliveryNote $note, ?int $receiptId): void
    {
        foreach ($request->file('attachments', []) as $file) {
            $note->attachments()->create([
                'internal_delivery_receipt_id' => $receiptId,
                'path' => StoredUpload::store($file, 'idn/'.$note->id),
                'original_name' => StoredUpload::downloadName($file->getClientOriginalName(), 'attachment'),
            ]);
        }

        foreach ($request->file('images', []) as $file) {
            $note->attachments()->create([
                'internal_delivery_receipt_id' => $receiptId,
                'path' => StoredUpload::store($file, 'idn/'.$note->id),
                'original_name' => StoredUpload::downloadName($file->getClientOriginalName(), 'image'),
            ]);
        }
    }

    private function authorizeView(User $user): void
    {
        abort_unless($this->access->allows($user, 'idn.view'), 403);
    }

    private function authorizeNote(User $user, InternalDeliveryNote $note, bool $destination = false): void
    {
        $this->authorizeView($user);

        foreach ([$note->source_project_id, $note->destination_project_id] as $projectId) {
            if (! $projectId) {
                continue;
            }

            $project = Project::query()->find($projectId);

            if ($destination && (int) $projectId !== (int) $note->destination_project_id) {
                continue;
            }

            if ($project && ! $this->access->canAccessProject($user, $project)) {
                abort(404);
            }
        }
    }

    private function assertLocationAccess(User $user, int $sourceId, int $destinationId): void
    {
        foreach ([$sourceId, $destinationId] as $id) {
            $location = InventoryLocation::query()->findOrFail($id);

            if ($location->project_id) {
                $project = Project::query()->findOrFail($location->project_id);
                abort_unless($this->access->canAccessProject($user, $project), 404);
            } elseif ($location->isCentralStore()) {
                abort_unless($this->access->allows($user, 'inventory.view') || $user->is_admin, 404);
            } elseif (! $this->access->seesAllProjects($user)) {
                abort(404);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function locationOptions(User $user, bool $activeOnly): array
    {
        return InventoryLocation::query()
            ->with('project')
            ->where('location_type', '!=', InventoryLocation::IN_TRANSIT)
            ->where('is_system', false)
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get()
            ->filter(function (InventoryLocation $location) use ($user) {
                if ($location->isCentralStore()) {
                    return $this->access->allows($user, 'inventory.view') || $user->is_admin;
                }

                if (! $location->project_id) {
                    return $this->access->seesAllProjects($user);
                }

                return $this->access->canAccessProject($user, $location->project);
            })
            ->map(fn (InventoryLocation $location) => [
                'id' => $location->id,
                'name' => $location->name,
                'code' => $location->location_code,
                'type' => $location->location_type,
                'project_id' => $location->project_id,
                'project' => $location->project?->project_name,
            ])
            ->values()
            ->all();
    }

    private function visibleQuery(User $user)
    {
        $query = InternalDeliveryNote::query();

        if ($this->access->seesAllProjects($user)) {
            return $query;
        }

        $ids = $user->projects()->pluck('projects.id');

        return $query->where(function ($inner) use ($ids): void {
            $inner->whereIn('source_project_id', $ids)
                ->orWhereIn('destination_project_id', $ids)
                ->orWhere(function ($central) use ($ids): void {
                    $central->whereNull('source_project_id')->whereIn('destination_project_id', $ids);
                })
                ->orWhere(function ($central) use ($ids): void {
                    $central->whereNull('destination_project_id')->whereIn('source_project_id', $ids);
                });
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function row(InternalDeliveryNote $note): array
    {
        return [
            'id' => $note->id,
            'idn_number' => $note->idn_number,
            'transfer_date' => $note->transfer_date?->toDateString(),
            'source' => $note->sourceLocation?->name,
            'destination' => $note->destinationLocation?->name,
            'source_project' => $note->sourceProject?->project_name,
            'destination_project' => $note->destinationProject?->project_name,
            'items' => $note->items->count(),
            'quantity' => $note->items->reduce(fn ($carry, $item) => Money::add($carry, (string) $item->quantity, 3), '0.000'),
            'requested_by' => $note->requester?->name,
            'dispatched_by' => $note->dispatcher?->name,
            'received_by' => $note->receiver?->name,
            'status' => $note->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(InternalDeliveryNote $note): array
    {
        $sourceProjectId = $note->source_project_id ? (int) $note->source_project_id : null;

        return [
            'id' => $note->id,
            'idn_number' => $note->idn_number,
            'transfer_date' => $note->transfer_date?->toDateString(),
            'source_location_id' => $note->source_location_id,
            'destination_location_id' => $note->destination_location_id,
            'source' => $note->sourceLocation?->name,
            'destination' => $note->destinationLocation?->name,
            'source_project' => $note->sourceProject?->project_name,
            'destination_project' => $note->destinationProject?->project_name,
            'expected_arrival_date' => $note->expected_arrival_date?->toDateString(),
            'vehicle_number' => $note->vehicle_number,
            'driver_name' => $note->driver_name,
            'remarks' => $note->remarks,
            'status' => $note->status,
            'requested_by' => $note->requester?->name,
            'approved_by' => $note->approver?->name,
            'approved_at' => $note->approved_at?->toDateTimeString(),
            'dispatched_by' => $note->dispatcher?->name,
            'dispatched_at' => $note->dispatched_at?->toDateTimeString(),
            'received_by' => $note->receiver?->name,
            'received_at' => $note->received_at?->toDateTimeString(),
            'rejection_reason' => $note->rejection_reason,
            'cancellation_reason' => $note->cancellation_reason,
            'items' => $note->items->map(function (InternalDeliveryNoteItem $item) use ($sourceProjectId, $note) {
                $accounted = Money::add(Money::add((string) $item->quantity_accepted, (string) $item->quantity_damaged, 3), (string) $item->quantity_short, 3);
                $remaining = Money::sub((string) $item->quantity, $accounted, 3);

                return [
                    'id' => $item->id,
                    'material_id' => $item->material_id,
                    'code' => $item->material?->material_code,
                    'name' => $item->material?->name,
                    'unit' => $item->unit,
                    'quantity' => (string) $item->quantity,
                    'unit_cost' => $item->unit_cost !== null ? (string) $item->unit_cost : null,
                    'amount' => $item->amount !== null ? (string) $item->amount : null,
                    'is_estimated' => (bool) $item->is_estimated,
                    'accepted' => (string) $item->quantity_accepted,
                    'damaged' => (string) $item->quantity_damaged,
                    'short' => (string) $item->quantity_short,
                    'remaining' => bccomp($remaining, '0', 3) === -1 ? '0.000' : $remaining,
                    'available' => $note->isEditable()
                        ? $this->stock->locationBalance($sourceProjectId, (int) $item->material_id, (int) $note->source_location_id)
                        : null,
                    'remarks' => $item->remarks,
                ];
            })->values(),
            'attachments' => $note->relationLoaded('attachments') ? $note->attachments->map(fn (IdnAttachment $file) => [
                'id' => $file->id,
                'name' => $file->original_name,
                'url' => route('inventory.transfers.attachments.show', [$note, $file]),
            ]) : [],
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user, InternalDeliveryNote $note): array
    {
        return [
            'edit' => $note->isEditable() && $this->access->allows($user, 'idn.edit'),
            'submit' => $note->status === InternalDeliveryNote::DRAFT && $this->access->allows($user, 'idn.submit'),
            'approve' => $note->status === InternalDeliveryNote::SUBMITTED && $this->access->allows($user, 'idn.approve'),
            'reject' => $note->status === InternalDeliveryNote::SUBMITTED && $this->access->allows($user, 'idn.reject'),
            'dispatch' => $note->status === InternalDeliveryNote::APPROVED && $this->access->allows($user, 'idn.dispatch'),
            'receive' => $note->isOpenForReceipt() && $this->access->allows($user, 'idn.receive'),
            'cancel' => in_array($note->status, [InternalDeliveryNote::DRAFT, InternalDeliveryNote::SUBMITTED, InternalDeliveryNote::APPROVED, InternalDeliveryNote::DISPATCHED], true)
                && $this->access->allows($user, 'idn.approve'),
            'print' => $this->access->allows($user, 'idn.print'),
        ];
    }

    /**
     * @return array{id: int, project_name: string, project_code: string}
     */
    private function card(Project $project): array
    {
        return [
            'id' => $project->id,
            'project_name' => $project->project_name,
            'project_code' => $project->project_code,
        ];
    }
}
