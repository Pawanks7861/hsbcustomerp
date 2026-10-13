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
        ]);

        $revision = $revisions->createNext($boq, $request->user(), $validated['revision_reason'] ?? null);

        return redirect()
            ->route('projects.boqs.show', [$project, $boq, 'revision' => $revision->id])
            ->with('success', 'Revision '.$revision->revision_number.' created.');
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
