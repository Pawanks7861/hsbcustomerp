<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\MaterialIssueItem;
use App\Models\MaterialReceiptItem;
use App\Models\Project;
use App\Models\StockAdjustment;
use App\Services\Access;
use App\Services\InventoryLocationService;
use App\Services\StockAdjustmentService;
use App\Support\Listing;
use App\Support\MaterialUnits;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MaterialController extends Controller
{
    public function index(Request $request, Project $project): Response
    {
        return $this->renderIndex($request, $project);
    }

    public function master(Request $request): Response
    {
        return $this->renderIndex($request, null);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        return $this->persist($request, $project);
    }

    public function storeMaster(Request $request): RedirectResponse
    {
        return $this->persist($request, null);
    }

    public function update(Request $request, Project $project, Material $material): RedirectResponse
    {
        $material->update($this->validateMaterial($request));

        return $this->backToIndex($project)->with('success', 'Material updated.');
    }

    public function updateMaster(Request $request, Material $material): RedirectResponse
    {
        $material->update($this->validateMaterial($request));

        return $this->backToIndex(null)->with('success', 'Material updated.');
    }

    public function destroy(Project $project, Material $material): RedirectResponse
    {
        return $this->deleteMaterial($material, $project);
    }

    public function destroyMaster(Material $material): RedirectResponse
    {
        return $this->deleteMaterial($material, null);
    }

    private function renderIndex(Request $request, ?Project $project): Response
    {
        $filters = Listing::filters($request);
        $status = $request->string('status')->toString();

        $query = Material::query()
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->orderBy('id');
        Listing::applySearch($query, $filters['search'], ['material_code', 'name', 'category', 'unit', 'description']);

        return Inertia::render('Inventory/Materials/Index', [
            'project' => $project ? $this->projectCard($project) : null,
            'central' => $project === null,
            'filters' => [...$filters, 'status' => in_array($status, ['active', 'inactive'], true) ? $status : ''],
            'units' => MaterialUnits::all(),
            'materials' => Listing::paginate($query, $request)->through(fn (Material $material) => $this->payload($material)),
            'canCreateUnit' => $request->user()?->is_admin || app(Access::class)->allows($request->user(), 'inventory.create'),
        ]);
    }

    private function persist(Request $request, ?Project $project): RedirectResponse
    {
        $validated = $this->validateMaterial($request);
        $opening = $this->openingQuantity($request, $project);
        $exception = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                DB::transaction(function () use ($validated, $request, $project, $opening): void {
                    $material = Material::query()->create([
                        ...$validated,
                        'material_code' => $this->nextCode(),
                        'created_by' => $request->user()->id,
                    ]);

                    if ($opening !== null) {
                        $this->recordOpening($request, $material, $opening);
                    }
                });

                return $this->backToIndex($project)->with('success', 'Material created.');
            } catch (UniqueConstraintViolationException $caught) {
                $exception = $caught;
            }
        }

        throw $exception;
    }

    private function deleteMaterial(Material $material, ?Project $project): RedirectResponse
    {
        $used = $material->ledgerEntries()->exists()
            || MaterialReceiptItem::query()->where('material_id', $material->id)->exists()
            || MaterialIssueItem::query()->where('material_id', $material->id)->exists()
            || StockAdjustment::query()->where('material_id', $material->id)->exists();

        if ($used) {
            return back()->withErrors([
                'material' => 'Materials with stock transactions cannot be deleted. Mark the material inactive instead.',
            ]);
        }

        $material->delete();

        return $this->backToIndex($project)->with('success', 'Material deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateMaterial(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit' => ['required', 'string', 'max:50', Rule::in(MaterialUnits::all())],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    private function openingQuantity(Request $request, ?Project $project): ?string
    {
        if ($project !== null || ! $request->filled('opening_quantity')) {
            return null;
        }

        $request->validate([
            'opening_quantity' => ['nullable', 'numeric', 'gt:0'],
        ]);

        abort_unless(
            $request->user()->is_admin || app(Access::class)->allows($request->user(), 'inventory.adjust'),
            403
        );

        return (string) $request->input('opening_quantity');
    }

    private function recordOpening(Request $request, Material $material, string $quantity): void
    {
        $location = app(InventoryLocationService::class)->ensureCentralStore();
        $service = app(StockAdjustmentService::class);
        $draft = $service->createDraft($request->user(), null, [
            'material_id' => $material->id,
            'location_id' => $location->id,
            'adjustment_date' => now()->toDateString(),
            'adjustment_type' => StockAdjustment::OPENING,
            'quantity' => $quantity,
            'reason' => 'Opening stock',
        ]);
        $service->post($request->user(), null, $draft);
    }

    private function nextCode(): string
    {
        $latest = Material::query()->orderByDesc('id')->lockForUpdate()->value('material_code');
        $sequence = 1;

        if (is_string($latest) && preg_match('/(\d+)$/', $latest, $matches) === 1) {
            $sequence = ((int) $matches[1]) + 1;
        }

        return sprintf('MAT-%06d', $sequence);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Material $material): array
    {
        return [
            'id' => $material->id,
            'material_code' => $material->material_code,
            'name' => $material->name,
            'category' => $material->category,
            'description' => $material->description,
            'unit' => $material->unit,
            'is_active' => $material->is_active,
        ];
    }

    private function backToIndex(?Project $project): RedirectResponse
    {
        return $project
            ? redirect()->route('projects.inventory.materials.index', $project)
            : redirect()->route('inventory.materials.index');
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
