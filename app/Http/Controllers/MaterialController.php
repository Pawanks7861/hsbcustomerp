<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\MaterialIssueItem;
use App\Models\MaterialReceiptItem;
use App\Models\Project;
use App\Models\StockAdjustment;
use App\Services\DocumentNumber;
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
        $search = trim($request->string('search')->toString());
        $status = $request->string('status')->toString();

        $materials = Material::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('material_code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%");
                });
            })
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->get();

        return Inertia::render('Inventory/Materials/Index', [
            'project' => $this->projectCard($project),
            'filters' => ['search' => $search, 'status' => $status],
            'units' => MaterialUnits::all(),
            'materials' => $materials->map(fn (Material $material) => $this->payload($material)),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $validated = $this->validateMaterial($request);

        $exception = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                DB::transaction(function () use ($validated, $request): void {
                    Material::query()->create([
                        ...$validated,
                        'material_code' => $this->nextCode(),
                        'created_by' => $request->user()->id,
                    ]);
                });

                return redirect()->route('projects.inventory.materials.index', $project)->with('success', 'Material created.');
            } catch (UniqueConstraintViolationException $caught) {
                $exception = $caught;
            }
        }

        throw $exception;
    }

    public function update(Request $request, Project $project, Material $material): RedirectResponse
    {
        $material->update($this->validateMaterial($request));

        return redirect()->route('projects.inventory.materials.index', $project)->with('success', 'Material updated.');
    }

    public function destroy(Project $project, Material $material): RedirectResponse
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

        return redirect()->route('projects.inventory.materials.index', $project)->with('success', 'Material deleted.');
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
            'unit' => ['required', 'string', Rule::in(MaterialUnits::all())],
            'minimum_stock' => ['required', 'numeric', 'gte:0'],
            'is_active' => ['required', 'boolean'],
        ]);
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
            'minimum_stock' => (string) $material->minimum_stock,
            'is_active' => $material->is_active,
        ];
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
