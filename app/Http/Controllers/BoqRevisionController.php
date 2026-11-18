<?php

namespace App\Http\Controllers;

use App\Models\Boq;
use App\Models\BoqRevision;
use App\Models\Project;
use App\Services\BoqRevisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BoqRevisionController extends Controller
{
    public function store(Request $request, Project $project, Boq $boq, BoqRevisionService $revisions): RedirectResponse
    {
        abort_unless($boq->project_id === $project->id, 404);

        $validated = $request->validate([
            'revision_reason' => ['nullable', 'string', 'max:2000'],
            'add_item' => ['nullable', 'boolean'],
        ]);

        $reason = $validated['revision_reason'] ?? null;
        $addItem = $request->boolean('add_item');

        if (($reason === null || trim((string) $reason) === '') && $addItem) {
            $reason = 'Add BOQ items';
        }

        $existing = $revisions->editableRevision($boq);
        $revision = $addItem
            ? $revisions->ensureEditable($boq, $request->user(), $reason)
            : $revisions->createNext($boq, $request->user(), $reason);

        $params = ['revision' => $revision->id];

        if ($addItem) {
            $params['add_item'] = 1;
        }

        $message = $existing && $existing->is($revision)
            ? 'Opened draft revision '.$revision->revision_number.'.'
            : 'Revision '.$revision->revision_number.' created.';

        return redirect()
            ->route('projects.boqs.show', [$project, $boq, ...$params])
            ->with('success', $message);
    }

    public function finalize(Project $project, Boq $boq, BoqRevision $revision, BoqRevisionService $revisions): RedirectResponse
    {
        abort_unless($boq->project_id === $project->id, 404);
        abort_unless($revision->boq_id === $boq->id, 404);

        $revisions->finalize($boq, $revision);

        return redirect()
            ->route('projects.boqs.show', [$project, $boq, 'revision' => $revision->id])
            ->with('success', 'Revision '.$revision->revision_number.' is now current.');
    }
}
