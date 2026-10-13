<?php

namespace App\Http\Controllers;

use App\Models\InventoryLocation;
use App\Models\Material;
use App\Models\Project;
use App\Services\Access;
use App\Services\ActivityLogger;
use App\Services\InventoryLocationService;
use App\Services\InventoryStock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InventoryLocationController extends Controller
{
    public function __construct(
        private Access $access,
        private InventoryLocationService $locations,
        private InventoryStock $stock,
        private ActivityLogger $activity,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($this->access->allows($request->user(), 'inventory.view'), 403);

        $locations = InventoryLocation::query()
            ->with(['project', 'responsibleUser'])
            ->where('is_system', false)
            ->orderBy('name')
            ->get()
            ->filter(function (InventoryLocation $location) use ($request) {
                if (! $location->project_id) {
                    return $this->access->seesAllProjects($request->user());
                }

                return $this->access->canAccessProject($request->user(), $location->project);
            })
            ->values()
            ->map(fn (InventoryLocation $location) => [
                'id' => $location->id,
                'location_code' => $location->location_code,
                'name' => $location->name,
                'location_type' => $location->location_type,
                'project_id' => $location->project_id,
                'project' => $location->project?->project_name,
                'address' => $location->address,
                'responsible_user_id' => $location->responsible_user_id,
                'responsible' => $location->responsibleUser?->name,
                'is_active' => $location->is_active,
                'is_default' => $location->is_default,
            ]);

        return Inertia::render('Inventory/Locations/Index', [
            'locations' => $locations,
            'projects' => Project::query()->visibleTo($request->user())->orderBy('project_name')->get(['id', 'project_name']),
            'types' => InventoryLocation::TYPES,
            'canManage' => $this->access->allows($request->user(), 'inventory.create'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->access->allows($request->user(), 'inventory.create'), 403);
        $data = $this->validated($request);

        if (! empty($data['project_id'])) {
            $project = Project::query()->findOrFail($data['project_id']);
            abort_unless($this->access->canAccessProject($request->user(), $project), 403);
        }

        $location = InventoryLocation::query()->create([
            ...$data,
            'location_code' => $this->locations->nextCode(),
            'is_system' => false,
            'is_default' => false,
        ]);
        $this->activity->log($request->user()->id, 'location.created', $location);

        return back()->with('success', 'Location created.');
    }

    public function update(Request $request, InventoryLocation $location): RedirectResponse
    {
        abort_unless($this->access->allows($request->user(), 'inventory.edit'), 403);
        abort_if($location->is_system, 403);
        $data = $this->validated($request);
        $location->update($data);
        $this->activity->log($request->user()->id, 'location.updated', $location);

        return back()->with('success', 'Location updated.');
    }

    public function stock(Request $request, InventoryLocation $location): \Illuminate\Http\JsonResponse
    {
        abort_unless($this->access->allows($request->user(), 'idn.view') || $this->access->allows($request->user(), 'inventory.view'), 403);

        if ($location->project_id) {
            abort_unless($this->access->canAccessProject($request->user(), $location->project), 404);
        }

        $materials = Material::query()->where('is_active', true)->orderBy('name')->get();
        $rows = [];

        foreach ($materials as $material) {
            if (! $location->project_id) {
                continue;
            }

            $available = $this->stock->locationBalance((int) $location->project_id, (int) $material->id, (int) $location->id);

            if (bccomp($available, '0', 3) !== 1) {
                continue;
            }

            $rows[] = [
                'id' => $material->id,
                'code' => $material->material_code,
                'name' => $material->name,
                'unit' => $material->unit,
                'available' => $available,
            ];
        }

        return response()->json($rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'location_type' => ['required', Rule::in(InventoryLocation::TYPES)],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'address' => ['nullable', 'string', 'max:2000'],
            'responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
