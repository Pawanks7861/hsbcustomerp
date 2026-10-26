<?php

namespace App\Http\Controllers;

use App\Models\InventoryLocation;
use App\Models\Material;
use App\Models\MaterialIssue;
use App\Models\MaterialReceipt;
use App\Models\Project;
use App\Models\StockLedgerEntry;
use App\Services\Access;
use App\Services\InventoryLocationService;
use App\Services\InventoryStock;
use App\Services\StockAdjustmentService;
use App\Support\Listing;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function __construct(
        private InventoryStock $stock,
        private StockAdjustmentService $adjustments,
        private InventoryLocationService $locations,
        private Access $access,
    ) {}

    public function dashboard(Request $request, Project $project): Response
    {
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        $balances = $this->locationBalances($request, $project);
        $withStock = 0;
        $out = 0;
        $seen = [];

        foreach ($balances as $row) {
            $id = (int) $row['material_id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            if (bccomp($row['total_available'], '0', 3) === 1) {
                $withStock++;
            }

            if ($row['status'] === 'Out of Stock') {
                $out++;
            }
        }

        $grns = MaterialReceipt::query()
            ->where('project_id', $project->id)
            ->where('document_kind', MaterialReceipt::KIND_GRN)
            ->when($from !== '', fn ($query) => $query->whereDate('receipt_date', '>=', $from))
            ->when($to !== '', fn ($query) => $query->whereDate('receipt_date', '<=', $to))
            ->with('purchaseOrder')
            ->latest('receipt_date')
            ->limit(5)
            ->get();

        $issues = MaterialIssue::query()
            ->where('project_id', $project->id)
            ->when($from !== '', fn ($query) => $query->whereDate('issue_date', '>=', $from))
            ->when($to !== '', fn ($query) => $query->whereDate('issue_date', '<=', $to))
            ->latest('issue_date')
            ->limit(5)
            ->get();

        return Inertia::render('Inventory/Dashboard', [
            'project' => $this->projectCard($project),
            'filters' => ['from' => $from, 'to' => $to],
            'stats' => [
                'active_materials' => Material::query()->where('is_active', true)->count(),
                'in_stock' => $withStock,
                'out_of_stock' => $out,
            ],
            'grns' => $grns->map(fn (MaterialReceipt $receipt) => [
                'id' => $receipt->id,
                'number' => $receipt->receipt_number,
                'date' => $receipt->receipt_date?->toDateString(),
                'po_number' => $receipt->purchaseOrder?->po_number,
                'status' => $receipt->status,
            ]),
            'issues' => $issues->map(fn (MaterialIssue $issue) => [
                'id' => $issue->id,
                'number' => $issue->issue_number,
                'date' => $issue->issue_date?->toDateString(),
                'issued_to' => $issue->issued_to,
                'status' => $issue->status,
            ]),
        ]);
    }

    public function stock(Request $request, Project $project): Response
    {
        $user = $request->user();

        return Inertia::render('Inventory/Stock', [
            'project' => $this->projectCard($project),
            'locations' => $this->adjustments->locationCards($user, $project),
            'filters' => [
                ...Listing::filters($request),
                'location_id' => $request->integer('location_id') ?: null,
            ],
            'rows' => Listing::paginateRows($this->locationBalances($request, $project), $request, [
                'material_code', 'material_name', 'unit', 'location', 'location_code', 'available', 'total_available',
                'total_received', 'total_issued', 'adjustments', 'status',
            ]),
            'canAdjust' => $this->access->allows($user, 'inventory.adjust') || $user->is_admin,
            'canImport' => $this->access->allows($user, 'inventory.import') || $user->is_admin,
        ]);
    }

    public function ledger(Request $request, Project $project): Response
    {
        $materialId = $request->integer('material_id') ?: null;
        $locationId = $request->integer('location_id') ?: null;
        $filters = Listing::filters($request);

        $query = StockLedgerEntry::query()
            ->where('project_id', $project->id)
            ->when($materialId, fn ($query) => $query->where('material_id', $materialId))
            ->when($locationId, function ($query) use ($locationId, $project) {
                $location = InventoryLocation::query()->find($locationId);
                if ($location && $location->is_default && (int) $location->project_id === (int) $project->id) {
                    $query->where(function ($inner) use ($locationId) {
                        $inner->where('location_id', $locationId)->orWhereNull('location_id');
                    });
                } else {
                    $query->where('location_id', $locationId);
                }
            })
            ->with(['material', 'location', 'postedBy'])
            ->orderBy('id');
        Listing::applySearch($query, $filters['search'], [
            'transaction_number', 'transaction_date', 'transaction_type', 'source_type', 'source_number',
            'stock_in', 'stock_out', 'balance', 'remarks',
            'material.name', 'material.material_code', 'location.name', 'postedBy.name',
        ]);

        return Inertia::render('Inventory/Ledger', [
            'project' => $this->projectCard($project),
            'materials' => Material::query()->orderBy('name')->get(['id', 'material_code', 'name']),
            'locations' => $this->adjustments->locationCards($request->user(), $project),
            'filters' => [...$filters, 'material_id' => $materialId, 'location_id' => $locationId],
            'entries' => Listing::paginate($query, $request)->through(fn (StockLedgerEntry $entry) => [
                'id' => $entry->id,
                'transaction_number' => $entry->transaction_number,
                'transaction_date' => $entry->transaction_date?->toDateString(),
                'material' => trim($entry->material?->material_code.' '.$entry->material?->name),
                'location' => $entry->location?->name,
                'transaction_type' => $entry->transaction_type,
                'source_type' => $entry->source_type,
                'source_number' => $entry->source_number,
                'stock_in' => (string) $entry->stock_in,
                'stock_out' => (string) $entry->stock_out,
                'balance' => (string) $entry->balance,
                'posted_by' => $entry->postedBy?->name,
                'remarks' => $entry->remarks,
            ]),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function locationBalances(Request $request, Project $project): array
    {
        $filterLocationId = $request->integer('location_id') ?: null;
        $default = $this->locations->ensureProjectStore($project);
        $locations = $this->adjustments->selectableLocations($request->user(), $project);

        if ($filterLocationId) {
            $locations = $locations->where('id', $filterLocationId)->values();
        }

        $materialIds = StockLedgerEntry::query()
            ->where('project_id', $project->id)
            ->distinct()
            ->pluck('material_id');

        $materials = Material::query()->whereIn('id', $materialIds)->orderBy('name')->get()->keyBy('id');
        $balances = $this->stock->projectBalances($project->id, $locations);
        $rows = [];

        foreach ($materialIds as $materialId) {
            $material = $materials->get($materialId);

            if (! $material) {
                continue;
            }

            $available = $balances['total'][(int) $materialId] ?? '0.000';
            $received = $balances['received'][(int) $materialId] ?? '0.000';
            $issued = $balances['issued'][(int) $materialId] ?? '0.000';
            $status = $this->status($available);

            foreach ($locations as $location) {
                $atLocation = $balances['at'][(int) $materialId][(int) $location->id] ?? '0.000';

                if (bccomp($atLocation, '0', 3) === 0) {
                    continue;
                }

                $rows[] = [
                    'material_id' => $material->id,
                    'material_code' => $material->material_code,
                    'material_name' => $material->name,
                    'unit' => $material->unit,
                    'location_id' => $location->id,
                    'location' => $location->name,
                    'location_code' => $location->location_code,
                    'is_default_location' => (int) $location->id === (int) $default->id,
                    'total_received' => $received,
                    'total_issued' => $issued,
                    'adjustments' => Money::sub(Money::add($available, $issued, 3), $received, 3),
                    'available' => $atLocation,
                    'total_available' => $available,
                    'status' => $status,
                ];
            }
        }

        return $rows;
    }

    private function status(string $available): string
    {
        return bccomp($available, '0', 3) === 1 ? 'Available' : 'Out of Stock';
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
