<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\Project;
use App\Models\StockAdjustment;
use App\Services\StockAdjustmentService;
use App\Support\StoredUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockAdjustmentController extends Controller
{
    public function __construct(private StockAdjustmentService $adjustments) {}

    public function index(Project $project): Response
    {
        $adjustments = $project->stockAdjustments()->with(['material', 'location'])->latest('adjustment_date')->get();

        return Inertia::render('Inventory/Adjustments/Index', [
            'project' => $this->projectCard($project),
            'adjustments' => $adjustments->map(fn (StockAdjustment $adjustment) => [
                'id' => $adjustment->id,
                'adjustment_number' => $adjustment->adjustment_number,
                'adjustment_date' => $adjustment->adjustment_date?->toDateString(),
                'material' => trim($adjustment->material?->material_code.' '.$adjustment->material?->name),
                'location' => $adjustment->location?->name,
                'adjustment_type' => $adjustment->adjustment_type,
                'quantity' => (string) $adjustment->quantity,
                'status' => $adjustment->status,
            ]),
        ]);
    }

    public function create(Request $request, Project $project): Response
    {
        $locations = $this->adjustments->locationCards($request->user(), $project);
        $defaultLocationId = collect($locations)->firstWhere('is_default', true)['id']
            ?? ($locations[0]['id'] ?? null);

        return Inertia::render('Inventory/Adjustments/Form', [
            'project' => $this->projectCard($project),
            'materials' => Material::query()->where('is_active', true)->orderBy('name')->get(['id', 'material_code', 'name', 'unit']),
            'locations' => $locations,
            'defaults' => [
                'location_id' => $request->integer('location_id') ?: $defaultLocationId,
                'adjustment_type' => $request->string('type')->toString() ?: StockAdjustment::OPENING,
                'material_id' => $request->integer('material_id') ?: null,
            ],
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $validated = $request->validate([
            'material_id' => ['required', 'integer', 'exists:materials,id'],
            'location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'adjustment_date' => ['required', 'date'],
            'adjustment_type' => ['required', Rule::in([StockAdjustment::OPENING, StockAdjustment::INCREASE, StockAdjustment::DECREASE])],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reference_number' => ['nullable', 'string', 'max:80'],
            'reason' => ['nullable', 'string', 'max:5000'],
            'attachment' => array_merge(['nullable'], StoredUpload::rules(StoredUpload::DOCUMENTS, 5120)),
        ]);

        if ($validated['adjustment_type'] !== StockAdjustment::OPENING && blank($validated['reason'] ?? null)) {
            return back()->withErrors(['reason' => 'Remarks are required for stock increases and decreases.'])->withInput();
        }

        $path = $request->hasFile('attachment')
            ? StoredUpload::store($request->file('attachment'), 'projects/'.$project->id.'/adjustments')
            : null;

        $adjustment = $this->adjustments->createDraft($request->user(), $project, [
            ...$validated,
            'attachment_path' => $path,
        ]);

        return redirect()->route('projects.inventory.adjustments.show', [$project, $adjustment])->with('success', 'Stock adjustment saved.');
    }

    public function show(Project $project, StockAdjustment $stockAdjustment): Response
    {
        $this->ensure($project, $stockAdjustment);
        $stockAdjustment->load(['material', 'location', 'creator', 'postedBy']);

        return Inertia::render('Inventory/Adjustments/Show', [
            'project' => $this->projectCard($project),
            'adjustment' => [
                'id' => $stockAdjustment->id,
                'adjustment_number' => $stockAdjustment->adjustment_number,
                'adjustment_date' => $stockAdjustment->adjustment_date?->toDateString(),
                'material' => trim($stockAdjustment->material?->material_code.' '.$stockAdjustment->material?->name),
                'unit' => $stockAdjustment->material?->unit,
                'location' => $stockAdjustment->location?->name,
                'location_code' => $stockAdjustment->location?->location_code,
                'adjustment_type' => $stockAdjustment->adjustment_type,
                'quantity' => (string) $stockAdjustment->quantity,
                'reference_number' => $stockAdjustment->reference_number,
                'reason' => $stockAdjustment->reason,
                'status' => $stockAdjustment->status,
                'created_by' => $stockAdjustment->creator?->name,
                'posted_by' => $stockAdjustment->postedBy?->name,
                'posted_at' => $stockAdjustment->posted_at?->toDateTimeString(),
                'has_attachment' => filled($stockAdjustment->attachment_path),
            ],
        ]);
    }

    public function attachment(Project $project, StockAdjustment $stockAdjustment): StreamedResponse
    {
        $this->ensure($project, $stockAdjustment);
        abort_unless(filled($stockAdjustment->attachment_path), 404);
        abort_unless(Storage::disk('local')->exists($stockAdjustment->attachment_path), 404);

        return StoredUpload::serve(
            $stockAdjustment->attachment_path,
            inlineIfSafe: true,
            expectedPrefix: 'projects/'.$project->id.'/adjustments',
        );
    }

    public function post(Request $request, Project $project, StockAdjustment $stockAdjustment): RedirectResponse
    {
        $this->ensure($project, $stockAdjustment);
        $this->adjustments->post($request->user(), $project, $stockAdjustment);

        return back()->with('success', 'Stock adjustment posted.');
    }

    private function ensure(Project $project, StockAdjustment $adjustment): void
    {
        abort_unless((int) $adjustment->project_id === (int) $project->id, 404);
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
