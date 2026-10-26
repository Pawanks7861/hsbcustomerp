<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\Project;
use App\Models\StockLedgerEntry;
use App\Services\DocumentNumber;
use App\Services\InventoryStock;
use App\Support\Listing;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MaterialIssueController extends Controller
{
    public function __construct(private InventoryStock $stock) {}

    public function index(Request $request, Project $project): Response
    {
        $filters = Listing::filters($request);
        $query = $project->materialIssues()->with('items.material')->latest('issue_date')->orderByDesc('id');
        Listing::applySearch($query, $filters['search'], [
            'issue_number', 'issue_date', 'issued_to', 'purpose', 'status', 'items.material.name', 'items.material.material_code',
        ]);

        return Inertia::render('Inventory/Issues/Index', [
            'project' => $this->projectCard($project),
            'filters' => $filters,
            'issues' => Listing::paginate($query, $request)->through(fn (MaterialIssue $issue) => [
                'id' => $issue->id,
                'issue_number' => $issue->issue_number,
                'issue_date' => $issue->issue_date?->toDateString(),
                'issued_to' => $issue->issued_to,
                'purpose' => $issue->purpose,
                'status' => $issue->status,
                'items' => $issue->items->count(),
            ]),
        ]);
    }

    public function create(Project $project): Response
    {
        return Inertia::render('Inventory/Issues/Form', [
            'project' => $this->projectCard($project),
            'issue' => null,
            'materials' => $this->materials($project),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $issue = $this->persist($request, $project, null);

        return redirect()->route('projects.inventory.issues.show', [$project, $issue])->with('success', 'Material issue saved.');
    }

    public function show(Project $project, MaterialIssue $materialIssue): Response
    {
        $this->ensure($project, $materialIssue);
        $materialIssue->load(['items.material', 'creator', 'postedBy']);

        return Inertia::render('Inventory/Issues/Show', [
            'project' => $this->projectCard($project),
            'issue' => [
                'id' => $materialIssue->id,
                'issue_number' => $materialIssue->issue_number,
                'issue_date' => $materialIssue->issue_date?->toDateString(),
                'issued_to' => $materialIssue->issued_to,
                'purpose' => $materialIssue->purpose,
                'status' => $materialIssue->status,
                'created_by' => $materialIssue->creator?->name,
                'posted_by' => $materialIssue->postedBy?->name,
                'posted_at' => $materialIssue->posted_at?->toDateTimeString(),
                'items' => $materialIssue->items->map(fn (MaterialIssueItem $item) => [
                    'material' => trim($item->material?->material_code.' '.$item->material?->name),
                    'unit' => $item->unit,
                    'quantity' => (string) $item->quantity,
                    'remarks' => $item->remarks,
                ]),
            ],
            'canPost' => $materialIssue->status === MaterialIssue::DRAFT,
            'canCancel' => $materialIssue->status === MaterialIssue::POSTED,
        ]);
    }

    public function update(Request $request, Project $project, MaterialIssue $materialIssue): RedirectResponse
    {
        $this->ensure($project, $materialIssue);
        abort_unless($materialIssue->status === MaterialIssue::DRAFT, 403);
        $this->persist($request, $project, $materialIssue);

        return redirect()->route('projects.inventory.issues.show', [$project, $materialIssue])->with('success', 'Material issue updated.');
    }

    public function post(Request $request, Project $project, MaterialIssue $materialIssue): RedirectResponse
    {
        $this->ensure($project, $materialIssue);

        DB::transaction(function () use ($request, $project, $materialIssue): void {
            $locked = MaterialIssue::query()->whereKey($materialIssue->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === MaterialIssue::DRAFT, 403);
            $locked->load('items');

            foreach ($locked->items as $item) {
                $available = $this->stock->balance($project->id, (int) $item->material_id);
                $quantity = Money::scale($item->quantity, 3) ?? '0.000';

                if (bccomp($quantity, $available, 3) === 1) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Issue quantity cannot exceed available stock.',
                    ]);
                }

                $this->stock->move(
                    $project->id,
                    (int) $item->material_id,
                    $locked->issue_date->toDateString(),
                    StockLedgerEntry::ISSUE,
                    'material_issue',
                    (int) $locked->id,
                    (int) $item->id,
                    $locked->issue_number,
                    '0',
                    $quantity,
                    $request->user()->id,
                    $locked->purpose,
                );
            }

            $locked->update([
                'status' => MaterialIssue::POSTED,
                'posted_at' => now(),
                'posted_by' => $request->user()->id,
            ]);
        });

        return back()->with('success', 'Material issue posted.');
    }

    public function cancel(Request $request, Project $project, MaterialIssue $materialIssue): RedirectResponse
    {
        $this->ensure($project, $materialIssue);

        DB::transaction(function () use ($request, $materialIssue): void {
            $locked = MaterialIssue::query()->whereKey($materialIssue->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === MaterialIssue::POSTED, 403);
            $entries = StockLedgerEntry::query()
                ->where('source_type', 'material_issue')
                ->where('source_id', $locked->id)
                ->orderBy('id')
                ->get();

            foreach ($entries as $entry) {
                $this->stock->move(
                    (int) $entry->project_id,
                    (int) $entry->material_id,
                    now()->toDateString(),
                    StockLedgerEntry::REVERSAL,
                    'reversal',
                    (int) $locked->id,
                    (int) $entry->id,
                    $locked->issue_number,
                    (string) $entry->stock_out,
                    '0',
                    $request->user()->id,
                    'Reversal of '.$locked->issue_number,
                );
            }

            $locked->update([
                'status' => MaterialIssue::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
            ]);
        });

        return back()->with('success', 'Material issue cancelled.');
    }

    private function persist(Request $request, Project $project, ?MaterialIssue $issue): MaterialIssue
    {
        $validated = $request->validate([
            'issue_date' => ['required', 'date'],
            'issued_to' => ['required', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.material_id' => ['required', 'integer', 'distinct', 'exists:materials,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $lines = [];

        foreach ($validated['items'] as $index => $row) {
            $material = Material::query()->findOrFail($row['material_id']);

            if (! $material->is_active) {
                throw ValidationException::withMessages([
                    "items.$index.material_id" => 'Select an active material.',
                ]);
            }

            $quantity = Money::scale($row['quantity'], 3);
            $available = $this->stock->balance($project->id, $material->id);

            if (bccomp($quantity, $available, 3) === 1) {
                throw ValidationException::withMessages([
                    "items.$index.quantity" => 'Issue quantity cannot exceed available stock of '.$available.'.',
                ]);
            }

            $lines[] = [
                'material_id' => $material->id,
                'unit' => $material->unit,
                'quantity' => $quantity,
                'remarks' => trim((string) ($row['remarks'] ?? '')) ?: null,
            ];
        }

        $save = function () use ($request, $project, $issue, $validated, $lines) {
            $attributes = [
                'issue_date' => $validated['issue_date'],
                'issued_to' => $validated['issued_to'],
                'purpose' => $validated['purpose'] ?? null,
            ];

            if ($issue) {
                $issue->update($attributes);
                $issue->items()->delete();
            } else {
                $issue = MaterialIssue::query()->create([
                    ...$attributes,
                    'project_id' => $project->id,
                    'issue_number' => DocumentNumber::next(MaterialIssue::class, 'issue_number', 'ISS'),
                    'status' => MaterialIssue::DRAFT,
                    'created_by' => $request->user()->id,
                ]);
            }

            $issue->items()->createMany($lines);

            return $issue;
        };

        if ($issue) {
            return DB::transaction($save);
        }

        $exception = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction($save);
            } catch (UniqueConstraintViolationException $caught) {
                $exception = $caught;
            }
        }

        throw $exception;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function materials(Project $project): array
    {
        return Material::query()->where('is_active', true)->orderBy('name')->get()->map(function (Material $material) use ($project) {
            return [
                'id' => $material->id,
                'label' => $material->material_code.' '.$material->name,
                'unit' => $material->unit,
                'available' => $this->stock->balance($project->id, $material->id),
            ];
        })->all();
    }

    private function ensure(Project $project, MaterialIssue $issue): void
    {
        abort_unless((int) $issue->project_id === (int) $project->id, 404);
    }

    /**
     * @return array{id: int, project_name: string, project_code: string}
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
