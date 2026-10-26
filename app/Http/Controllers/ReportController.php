<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\Vendor;
use App\Services\Access;
use App\Services\DocumentPdf;
use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportQueryService;
use App\Services\Reports\ReportSpreadsheet;
use App\Support\DocumentStatus;
use App\Support\Listing;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ReportController extends Controller
{
    public function index(Request $request, Access $access): Response
    {
        abort_unless($access->allows($request->user(), 'reports.view'), 403);

        return Inertia::render('Reports/Index', [
            'catalog' => ReportCatalog::all(),
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name', 'vendor_code']),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name', 'client_code']),
            'projects' => Project::query()->visibleTo($request->user())->orderBy('project_name')->get(['id', 'project_name', 'project_code']),
        ]);
    }

    public function show(string $report, Request $request, Access $access, ReportQueryService $queries): Response
    {
        abort_unless($access->allows($request->user(), 'reports.view'), 403);

        $definition = ReportCatalog::find($report);
        abort_unless($definition !== null, 404);

        $filters = $this->filters($request);
        $payload = $queries->run($report, $request->user(), $filters);

        $listing = Listing::filters($request);
        $keys = collect($definition['columns'] ?? [])->pluck('key')->filter()->values()->all();
        $payload['rows'] = Listing::paginateRows($payload['rows'] ?? [], $request, $keys);
        if (! empty($payload['sections'])) {
            $payload['sections'] = collect($payload['sections'])->map(function (array $section) use ($listing) {
                $sectionKeys = collect($section['columns'] ?? [])->pluck('key')->filter()->values()->all();
                $section['rows'] = Listing::filterRows($section['rows'] ?? [], $listing['search'], $sectionKeys)->all();

                return $section;
            })->all();
        }

        return Inertia::render('Reports/Show', [
            ...$this->showPayload($definition, $filters, $payload, $request->user(), $access),
            'listing' => $listing,
        ]);
    }

    public function exportExcel(string $report, Request $request, Access $access, ReportQueryService $queries, ReportSpreadsheet $excel): HttpResponse
    {
        abort_unless($access->allows($request->user(), 'reports.export'), 403);

        $definition = ReportCatalog::find($report);
        abort_unless($definition !== null, 404);

        $filters = $this->filters($request);
        $payload = $queries->run($report, $request->user(), $filters);

        return $excel->download(
            $definition['title'],
            $definition['columns'],
            $payload['rows'] ?? [],
            $payload['totals'] ?? null,
            $payload['sections'] ?? null,
            $report.'-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    public function exportPdf(string $report, Request $request, Access $access, ReportQueryService $queries, DocumentPdf $pdf): HttpResponse
    {
        $user = $request->user();
        abort_unless(
            $access->allows($user, 'reports.print') || $access->allows($user, 'reports.export'),
            403
        );

        $definition = ReportCatalog::find($report);
        abort_unless($definition !== null, 404);

        $filters = $this->filters($request);
        $payload = $queries->run($report, $user, $filters);

        return $pdf->download('pdf.report', [
            'title' => $definition['title'],
            'number' => '',
            'definition' => $definition,
            'filters' => $this->filterLabels($filters),
            'columns' => $definition['columns'],
            'rows' => $payload['rows'] ?? [],
            'totals' => $payload['totals'] ?? [],
            'sections' => $payload['sections'] ?? [],
        ], $report.'-'.now()->format('Y-m-d'));
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function showPayload(array $definition, array $filters, array $payload, $user, Access $access): array
    {
        return [
            'report' => $definition,
            'filters' => $filters,
            'rows' => $payload['rows'] ?? [],
            'totals' => $payload['totals'] ?? [],
            'sections' => $payload['sections'] ?? [],
            'projects' => Project::query()->visibleTo($user)->orderBy('project_name')->get(['id', 'project_name', 'project_code']),
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name', 'vendor_code']),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name', 'client_code']),
            'statusOptions' => DocumentStatus::all(),
            'abilities' => [
                'export' => $access->allows($user, 'reports.export'),
                'print' => $access->allows($user, 'reports.print') || $access->allows($user, 'reports.export'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'project_id' => $request->integer('project_id') ?: null,
            'vendor_id' => $request->integer('vendor_id') ?: null,
            'client_id' => $request->integer('client_id') ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{label: string, value: string}>
     */
    private function filterLabels(array $filters): array
    {
        $labels = [];

        if ($filters['project_id']) {
            $project = Project::query()->find($filters['project_id']);
            $labels[] = ['label' => 'Project', 'value' => $project?->project_name ?? (string) $filters['project_id']];
        }

        if ($filters['vendor_id']) {
            $vendor = Vendor::query()->find($filters['vendor_id']);
            $labels[] = ['label' => 'Vendor', 'value' => $vendor?->name ?? (string) $filters['vendor_id']];
        }

        if ($filters['client_id']) {
            $client = Client::query()->find($filters['client_id']);
            $labels[] = ['label' => 'Client', 'value' => $client?->name ?? (string) $filters['client_id']];
        }

        if ($filters['from']) {
            $labels[] = ['label' => 'From', 'value' => $filters['from']];
        }

        if ($filters['to']) {
            $labels[] = ['label' => 'To', 'value' => $filters['to']];
        }

        if ($filters['status']) {
            $labels[] = ['label' => 'Status', 'value' => $filters['status']];
        }

        return $labels;
    }
}
