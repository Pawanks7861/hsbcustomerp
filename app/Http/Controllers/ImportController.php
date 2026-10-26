<?php

namespace App\Http\Controllers;

use App\Imports\ImportEngine;
use App\Imports\ImportRegistry;
use App\Imports\Spreadsheet\SpreadsheetIO;
use App\Imports\Support\DuplicateMode;
use App\Models\ImportBatch;
use App\Models\Project;
use App\Services\Access;
use App\Support\Listing;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function __construct(
        private ImportEngine $engine,
        private ImportRegistry $registry,
        private SpreadsheetIO $spreadsheet,
        private Access $access,
    ) {}

    public function show(Request $request, string $module): Response
    {
        if (in_array($module, ['store', 'grn', 'invoices', 'idn', 'material_issues', 'boq'], true)) {
            return $this->restricted($request, $module);
        }

        return $this->wizard($request, $module);
    }

    private function restricted(Request $request, string $module): Response
    {
        $map = [
            'store' => [
                'permission' => 'store.import',
                'title' => 'Import Store Receipts',
                'message' => 'Bulk import of store receipts is restricted to protect stock integrity. Create receipts through the normal Store workflow, or import Materials first.',
                'alternatives' => [
                    ['label' => 'Import Materials', 'href' => '/imports/materials'],
                    ['label' => 'Import Inventory Locations', 'href' => '/imports/inventory_locations'],
                ],
            ],
            'grn' => [
                'permission' => 'grn.import',
                'title' => 'Import GRNs',
                'message' => 'GRNs cannot be bulk-posted from Excel. Create draft GRNs from approved POs in the GRN screens so quantities, inspection, and stock posting stay controlled.',
                'alternatives' => [
                    ['label' => 'Import Purchase Orders (draft from approved PR)', 'href' => '/imports/purchase_orders'],
                    ['label' => 'Import Materials', 'href' => '/imports/materials'],
                ],
            ],
            'invoices' => [
                'permission' => 'invoices.import',
                'title' => 'Import Purchase Invoices',
                'message' => 'Purchase invoices are not bulk-imported to protect partial-invoice rules, round-off, and vendor ledger integrity. Create invoices from the normal invoice screens.',
                'alternatives' => [
                    ['label' => 'Import Vendors', 'href' => '/imports/vendors'],
                    ['label' => 'Import Purchase Orders (draft)', 'href' => '/imports/purchase_orders'],
                ],
            ],
            'idn' => [
                'permission' => 'idn.import',
                'title' => 'Import Internal Delivery Notes',
                'message' => 'Internal deliveries move stock between locations. Create IDNs in the Transfers screens so dispatch/receive posting cannot be double-applied by a spreadsheet.',
                'alternatives' => [
                    ['label' => 'Import Inventory Locations', 'href' => '/imports/inventory_locations'],
                    ['label' => 'Import Materials', 'href' => '/imports/materials'],
                ],
            ],
            'material_issues' => [
                'permission' => 'inventory.import',
                'title' => 'Import Material Issues',
                'message' => 'Material issues post stock out of stores. Create draft issues in Inventory and post them through the existing approval workflow.',
                'alternatives' => [
                    ['label' => 'Import Materials', 'href' => '/imports/materials'],
                    ['label' => 'Import Location Stock', 'href' => '/imports/stock_adjustments'],
                ],
            ],
            'boq' => [
                'permission' => 'boq.import',
                'title' => 'Import BOQ Items',
                'message' => 'BOQ Excel import appends into a draft BOQ revision. Open the BOQ you want to update, then use Download Template / Import Excel on that BOQ page. Finalized revisions cannot be altered by import.',
                'alternatives' => [
                    ['label' => 'Open Projects to choose a BOQ', 'href' => '/projects'],
                ],
            ],
        ];

        $config = $map[$module];
        $this->authorizeImport($request, $config['permission']);

        return Inertia::render('Imports/Restricted', [
            'title' => $config['title'],
            'message' => $config['message'],
            'returnUrl' => $request->string('return')->toString() ?: null,
            'alternatives' => $config['alternatives'],
        ]);
    }

    public function template(Request $request, string $module): StreamedResponse
    {
        $definition = $this->engine->definition($module);
        $this->authorizeImport($request, $definition->permission());

        $format = $request->string('format')->toString() === 'csv' ? 'csv' : 'xlsx';
        $project = $this->resolveProject($request);

        return $this->spreadsheet->download($definition, $format, $request->user(), $project);
    }

    public function preview(Request $request, string $module): Response
    {
        $definition = $this->engine->definition($module);
        $this->authorizeImport($request, $definition->permission());

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt', 'extensions:xlsx,xls,csv,txt'],
            'duplicate_mode' => ['required', Rule::in($definition->supportedModes())],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'return' => ['nullable', 'string', 'max:500'],
        ]);

        $project = $this->resolveProject($request);

        $preview = $this->engine->preview(
            $module,
            $request->file('file'),
            $request->user(),
            $data['duplicate_mode'] ?? DuplicateMode::CREATE_ONLY,
            $project,
        );

        return $this->wizard($request, $module, $preview, null, $project);
    }

    public function confirm(Request $request, ImportBatch $batch): Response
    {
        $definition = $this->engine->definition($batch->module);
        $this->authorizeImport($request, $definition->permission());

        $project = null;
        if ($batch->project_id) {
            $project = Project::query()->find($batch->project_id);
            abort_unless($project instanceof Project, 404);
            $this->access->abortUnlessCanAccessProject($request->user(), $project);
        }

        $result = $this->engine->confirm($batch, $request->user(), $project);

        return $this->wizard($request, $batch->module, null, $result, $project);
    }

    public function history(Request $request): Response
    {
        abort_unless(
            $this->access->allows($request->user(), 'projects.view')
            || $request->user()->is_admin,
            403
        );

        $filters = Listing::filters($request);
        $query = ImportBatch::query()
            ->with('uploader:id,name')
            ->when(
                ! $this->access->seesAllProjects($request->user()) && ! $request->user()->is_admin,
                fn ($q) => $q->where('uploaded_by', $request->user()->id)
            )
            ->latest()
            ->orderByDesc('id');
        Listing::applySearch($query, $filters['search'], ['batch_number', 'module', 'original_filename', 'status', 'created_at', 'uploader.name']);

        return Inertia::render('Imports/History', [
            'batches' => Listing::paginate($query, $request)->through(fn (ImportBatch $batch) => $this->engine->batchPayload($batch)),
            'filters' => $filters,
            'catalog' => collect($this->registry->catalog())
                ->filter(fn (array $item) => $this->access->allows($request->user(), $item['permission']) || $request->user()->is_admin)
                ->values(),
        ]);
    }

    public function errorReport(Request $request, ImportBatch $batch): StreamedResponse
    {
        abort_unless($batch->uploaded_by === $request->user()->id || $request->user()->is_admin, 403);

        $definition = $this->engine->definition($batch->module);
        $this->authorizeImport($request, $definition->permission());

        return $this->spreadsheet->errorReport($batch->error_report ?? [], 'csv');
    }

    private function wizard(
        Request $request,
        string $module,
        ?array $preview = null,
        ?array $result = null,
        ?Project $project = null,
    ): Response {
        $definition = $this->engine->definition($module);
        $this->authorizeImport($request, $definition->permission());
        $project ??= $this->resolveProject($request);

        return Inertia::render('Imports/Wizard', [
            'module' => $module,
            'label' => $definition->label(),
            'permission' => $definition->permission(),
            'instructions' => $definition->instructions(),
            'modes' => $definition->supportedModes(),
            'defaultMode' => $definition->defaultMode(),
            'columns' => collect($definition->columns())->map(fn ($column) => [
                'key' => $column->key,
                'heading' => $column->heading,
                'required' => $column->required,
                'help' => $column->help,
            ])->values(),
            'project' => $project ? [
                'id' => $project->id,
                'project_code' => $project->project_code,
                'project_name' => $project->project_name,
            ] : null,
            'returnUrl' => $request->string('return')->toString() ?: null,
            'preview' => $preview,
            'result' => $result,
        ]);
    }

    private function authorizeImport(Request $request, string $permission): void
    {
        abort_unless($this->access->allows($request->user(), $permission) || $request->user()->is_admin, 403);
    }

    private function resolveProject(Request $request): ?Project
    {
        $id = $request->integer('project_id') ?: null;
        if (! $id) {
            return null;
        }

        $project = Project::query()->findOrFail($id);
        $this->access->abortUnlessCanAccessProject($request->user(), $project);

        return $project;
    }
}
