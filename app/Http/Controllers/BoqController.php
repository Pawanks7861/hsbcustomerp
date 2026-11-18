<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBoqRequest;
use App\Http\Requests\UpdateBoqRequest;
use App\Models\Boq;
use App\Models\Project;
use App\Models\BoqRevision;
use App\Services\BoqItemSync;
use App\Services\BoqReceiptProgress;
use App\Services\BoqRevisionService;
use App\Support\BoqTotals;
use App\Support\Listing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BoqController extends Controller
{
    public function index(Request $request, Project $project): Response
    {
        $filters = Listing::filters($request);
        $query = $project->boqs()
            ->with(['currentRevision', 'currentItems.budgets', 'currentItems.children'])
            ->orderBy('id');
        Listing::applySearch($query, $filters['search'], ['boq_number', 'title', 'gst_percentage', 'currentRevision.revision_number']);

        return Inertia::render('Boqs/Index', [
            'project' => $this->projectIdentity($project),
            'boqs' => Listing::paginate($query, $request)->through(fn (Boq $boq) => [
                'id' => $boq->id,
                'boq_number' => $boq->boq_number,
                'title' => $boq->title,
                'revision_number' => $boq->currentRevision?->revision_number,
                'gst_percentage' => (string) $boq->gst_percentage,
                'grand_total' => $boq->grandTotal(),
                'budget_total' => $boq->budgetTotal(),
            ]),
            'filters' => $filters,
        ]);
    }

    public function create(Project $project): Response
    {
        return Inertia::render('Boqs/Create', [
            'project' => $this->projectIdentity($project),
        ]);
    }

    public function store(StoreBoqRequest $request, Project $project, BoqRevisionService $revisions): RedirectResponse
    {
        $boq = $project->boqs()->create([
            ...$request->validated(),
            'boq_number' => $revisions->nextNumber($project->id),
        ]);

        return redirect()
            ->route('projects.boqs.show', [$project, $boq])
            ->with('success', 'BOQ created.');
    }

    public function show(Project $project, Boq $boq, BoqReceiptProgress $receipts): Response
    {
        $this->ensureBoq($project, $boq);
        $revision = $this->selectedRevision($boq);
        $payload = BoqTotals::boq($boq, $revision);
        $payload['revisions'] = $boq->revisions()->get()->map(fn (BoqRevision $row) => [
            'id' => $row->id,
            'revision_number' => $row->revision_number,
            'revision_date' => $row->revision_date?->toDateString(),
            'status' => $row->status,
            'is_current' => (int) $boq->current_revision_id === (int) $row->id,
        ])->values();
        $ids = collect($payload['items'])->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        $accepted = $receipts->acceptedFor($ids);
        $recorded = $receipts->recordedFor($ids);
        $payload['items'] = collect($payload['items'])->map(function (array $item) use ($accepted, $recorded) {
            $used = $accepted[(int) ($item['id'] ?? 0)] ?? '0.000';
            $received = $recorded[(int) ($item['id'] ?? 0)] ?? '0.000';
            $boqQuantity = $item['quantity'] === null || $item['quantity'] === '' ? null : (string) $item['quantity'];
            $item['quantity_usage'] = $boqQuantity !== null && (bccomp($received, '0', 3) === 1 || bccomp($used, '0', 3) === 1)
                ? [
                    'used_quantity' => $used,
                    'received_quantity' => $received,
                    'boq_quantity' => $boqQuantity,
                    'exceeded' => bccomp($received, $boqQuantity, 3) === 1,
                    'unit' => $item['unit'] ?? null,
                ]
                : null;

            return $item;
        })->all();

        return Inertia::render('Boqs/Show', [
            'project' => $this->projectIdentity($project),
            'boq' => $payload,
        ]);
    }

    public function update(
        UpdateBoqRequest $request,
        Project $project,
        Boq $boq,
        BoqItemSync $sync,
        BoqRevisionService $revisions,
    ): RedirectResponse {
        $this->ensureBoq($project, $boq);

        $validated = $request->validated();
        $shouldFinalize = ! empty($validated['finalize']);

        $revision = DB::transaction(function () use ($validated, $boq, $sync, $revisions, $shouldFinalize) {
            $boq->update([
                'title' => $validated['title'],
                'gst_percentage' => $validated['gst_percentage'],
            ]);

            $revision = null;

            if (! empty($validated['revision_id'])) {
                $revision = $boq->revisions()->whereKey($validated['revision_id'])->firstOrFail();
            }

            $sync->sync($boq, $validated['items'], $revision);

            $revision ??= $revisions->editableRevision($boq) ?? $boq->fresh()->currentRevision;

            if ($shouldFinalize && $revision) {
                $revisions->finalize($boq, $revision);
            }

            return $revision;
        });

        if ($shouldFinalize && $revision) {
            return redirect()
                ->route('projects.boqs.show', [$project, $boq, 'revision' => $revision->id])
                ->with('success', 'BOQ saved and finalized. New items are available for procurement.');
        }

        $route = [$project, $boq];

        if ($revision) {
            $route['revision'] = $revision->id;
        }

        return redirect()
            ->route('projects.boqs.show', $route)
            ->with('success', 'BOQ saved.');
    }

    public function destroy(Project $project, Boq $boq): RedirectResponse
    {
        $this->ensureBoq($project, $boq);

        if ($boq->purchaseRequests()->exists() || $boq->purchaseOrders()->exists()) {
            return back()->with('error', 'This BOQ cannot be deleted because procurement records exist.');
        }

        $boq->delete();

        return redirect()
            ->route('projects.boqs.index', $project)
            ->with('success', 'BOQ deleted.');
    }

    private function projectIdentity(Project $project): array
    {
        return [
            'id' => $project->id,
            'project_name' => $project->project_name,
            'project_code' => $project->project_code,
            'description' => $project->description,
        ];
    }

    private function ensureBoq(Project $project, Boq $boq): void
    {
        abort_unless($boq->project_id === $project->id, 404);
    }

    private function selectedRevision(Boq $boq): ?BoqRevision
    {
        $requested = request()->integer('revision');

        if ($requested) {
            return $boq->revisions()->whereKey($requested)->firstOrFail();
        }

        return $boq->revisions()->where('status', BoqRevision::DRAFT)->orderByDesc('revision_number')->first()
            ?? $boq->currentRevision;
    }
}
