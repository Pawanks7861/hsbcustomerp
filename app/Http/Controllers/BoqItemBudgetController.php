<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBoqItemBudgetRequest;
use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\BoqItemBudget;
use App\Models\Project;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BoqItemBudgetController extends Controller
{
    public function edit(Project $project, Boq $boq, BoqItem $item): Response
    {
        $this->ensureItem($project, $boq, $item);

        return Inertia::render('Boqs/Budget', [
            'project' => $this->projectIdentity($project),
            'boq' => [
                'id' => $boq->id,
                'title' => $boq->title,
            ],
            'item' => [
                'id' => $item->id,
                'item_number' => $item->item_number,
                'description' => $item->description,
                'budget_total' => $item->budgetTotal(),
            ],
            'entries' => $this->entries($item),
        ]);
    }

    public function update(UpdateBoqItemBudgetRequest $request, Project $project, Boq $boq, BoqItem $item): RedirectResponse
    {
        $this->ensureItem($project, $boq, $item);

        DB::transaction(function () use ($request, $item): void {
            $item->budgets()->delete();

            foreach (array_values($request->validated('entries')) as $index => $entry) {
                $item->budgets()->create([
                    'name' => trim($entry['name']),
                    'amount' => Money::scale($entry['amount'], 2),
                    'sort_order' => $index,
                ]);
            }
        });

        return redirect()
            ->route('projects.boqs.show', [$project, $boq])
            ->with('success', 'Budget saved.');
    }

    public function destroy(Project $project, Boq $boq, BoqItem $item, BoqItemBudget $budget): RedirectResponse
    {
        $this->ensureItem($project, $boq, $item);
        abort_unless($budget->boq_item_id === $item->id, 404);

        $firstDefaultId = $item->budgets()
            ->where('name', $budget->name)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('id');

        if ($budget->isDefault() && (int) $firstDefaultId === $budget->id) {
            return back()->withErrors([
                'budget' => 'Default budget heads cannot be deleted.',
            ]);
        }

        $budget->delete();

        return back()->with('success', 'Budget entry deleted.');
    }

    private function entries(BoqItem $item): array
    {
        $item->loadMissing('budgets');

        if ($item->budgets->isEmpty()) {
            return collect(BoqItemBudget::DEFAULTS)
                ->values()
                ->map(fn (string $name, int $index) => [
                    'id' => null,
                    'name' => $name,
                    'amount' => '0.00',
                    'sort_order' => $index,
                    'is_default' => true,
                ])
                ->all();
        }

        return $item->budgets->map(fn (BoqItemBudget $budget) => [
            'id' => $budget->id,
            'name' => $budget->name,
            'amount' => (string) $budget->amount,
            'sort_order' => $budget->sort_order,
            'is_default' => $budget->isDefault(),
        ])->all();
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

    private function ensureItem(Project $project, Boq $boq, BoqItem $item): void
    {
        abort_unless($boq->project_id === $project->id, 404);
        abort_unless($item->boq_id === $boq->id, 404);

        $revision = $item->revision;
        $isCurrent = (int) $item->boq_revision_id === (int) $boq->current_revision_id;
        abort_unless($revision && ($revision->isDraft() || $isCurrent), 403);
    }
}
