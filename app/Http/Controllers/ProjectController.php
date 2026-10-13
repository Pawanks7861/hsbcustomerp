<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use Illuminate\Http\Request;
use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\Project;
use App\Support\BoqTotals;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $projects = Project::query()
            ->visibleTo($request->user())
            ->with(['boqs.items.budgets', 'boqs.items.children'])
            ->orderBy('project_code')
            ->get()
            ->map(fn (Project $project) => BoqTotals::projectPayload($project));

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Projects/Form', [
            'project' => null,
        ]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $project = Project::query()->create($request->validated());

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'Project created.');
    }

    public function show(Project $project): Response
    {
        $project->load(['boqs.currentItems']);

        return Inertia::render('Projects/Dashboard', [
            'project' => BoqTotals::projectPayload($project),
            'boqs' => $project->boqs
                ->sortBy('boq_number')
                ->values()
                ->map(fn (Boq $boq) => [
                    'id' => $boq->id,
                    'boq_number' => $boq->boq_number,
                    'title' => $boq->title,
                    'items' => $boq->currentItems->map(fn (BoqItem $item) => [
                        'id' => $item->id,
                        'item_number' => $item->item_number,
                        'service_code' => $item->service_code,
                        'description' => $item->description,
                        'unit' => $item->unit,
                        'quantity' => $item->quantity,
                        'rate' => $item->rate,
                        'amount' => $item->amount,
                        'is_section' => (bool) $item->is_section,
                    ])->values(),
                ]),
        ]);
    }

    public function edit(Project $project): Response
    {
        return Inertia::render('Projects/Form', [
            'project' => [
                'id' => $project->id,
                'project_name' => $project->project_name,
                'project_code' => $project->project_code,
                'description' => $project->description,
            ],
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());

        return redirect()
            ->route('projects.index')
            ->with('success', 'Project updated.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        if ($project->purchaseRequests()->exists() || $project->purchaseOrders()->exists()) {
            return back()->with('error', 'This project cannot be deleted because procurement records exist.');
        }

        $project->delete();

        return redirect()
            ->route('projects.index')
            ->with('success', 'Project deleted.');
    }
}
