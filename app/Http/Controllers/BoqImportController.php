<?php

namespace App\Http\Controllers;

use App\Models\Boq;
use App\Models\Project;
use App\Services\BoqRevisionService;
use App\Services\BoqSpreadsheet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

class BoqImportController extends Controller
{
    public function __construct(private BoqSpreadsheet $spreadsheet) {}

    public function template(Project $project, Boq $boq): StreamedResponse
    {
        $this->ensureBoq($project, $boq);

        return response()->streamDownload(function (): void {
            IOFactory::createWriter($this->spreadsheet->template(), 'Xlsx')->save('php://output');
        }, 'BOQ-Import-Template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function create(Project $project, Boq $boq): Response
    {
        $this->ensureBoq($project, $boq);
        $preview = session($this->sessionKey($boq));

        return Inertia::render('Boqs/Import', [
            'project' => $this->projectCard($project),
            'boq' => [
                'id' => $boq->id,
                'title' => $boq->title,
            ],
            'existingCount' => $boq->items()->count(),
            'preview' => is_array($preview) ? $preview : null,
        ]);
    }

    public function preview(Request $request, Project $project, Boq $boq): RedirectResponse
    {
        $this->ensureBoq($project, $boq);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'extensions:xlsx,xls', 'max:10240'],
        ]);

        $parsed = $this->spreadsheet->parse($request->file('file')->getRealPath());
        session([$this->sessionKey($boq) => $parsed]);

        return redirect()->route('projects.boqs.import', [$project, $boq]);
    }

    public function confirm(Project $project, Boq $boq): RedirectResponse
    {
        $this->ensureBoq($project, $boq);
        $preview = session($this->sessionKey($boq));

        if (! is_array($preview) || ($preview['can_confirm'] ?? false) !== true) {
            return back()->withErrors([
                'import' => 'Correct the Excel file before importing.',
            ]);
        }

        $revision = app(BoqRevisionService::class)->editableRevision($boq);

        if (! $revision) {
            return back()->withErrors([
                'import' => 'Create a draft revision before importing into a finalized BOQ.',
            ]);
        }

        DB::transaction(function () use ($boq, $preview, $revision): void {
            $scoped = $boq->items()->where('boq_revision_id', $revision->id);
            $next = $scoped->exists()
                ? ((int) $scoped->max('sort_order')) + 1
                : 0;

            foreach ($preview['rows'] as $row) {
                if (! in_array($row['status'] ?? '', ['valid', 'header'], true)) {
                    continue;
                }

                $section = $row['status'] === 'header';

                $boq->items()->create([
                    'boq_revision_id' => $revision->id,
                    'sort_order' => $next,
                    'item_number' => trim((string) ($row['sr_no'] ?? '')),
                    'service_code' => $this->nullable($row['service_code'] ?? null),
                    'description' => (string) $row['description'],
                    'unit' => $this->nullable($row['unit'] ?? null),
                    'quantity' => $section ? null : ($row['quantity'] ?? null),
                    'rate' => $section ? null : ($row['rate'] ?? null),
                    'is_section' => $section,
                    'parent_id' => null,
                ]);
                $next++;
            }
        });

        session()->forget($this->sessionKey($boq));

        return redirect()
            ->route('projects.boqs.show', [$project, $boq])
            ->with('success', 'BOQ items imported.');
    }

    public function cancel(Project $project, Boq $boq): RedirectResponse
    {
        $this->ensureBoq($project, $boq);
        session()->forget($this->sessionKey($boq));

        return redirect()->route('projects.boqs.show', [$project, $boq]);
    }

    private function ensureBoq(Project $project, Boq $boq): void
    {
        abort_unless($boq->project_id === $project->id, 404);
    }

    private function sessionKey(Boq $boq): string
    {
        return 'boq-import.'.$boq->id;
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    /**
     * @return array<string, mixed>
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
