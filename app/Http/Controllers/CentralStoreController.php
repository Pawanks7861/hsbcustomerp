<?php

namespace App\Http\Controllers;

use App\Models\InventoryLocation;
use App\Models\Material;
use App\Models\StockAdjustment;
use App\Models\StockLedgerEntry;
use App\Services\Access;
use App\Services\InventoryLocationService;
use App\Services\InventoryStock;
use App\Services\StockAdjustmentService;
use App\Support\Listing;
use App\Support\Money;
use App\Support\StoredUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CentralStoreController extends Controller
{
    public function __construct(
        private InventoryStock $stock,
        private StockAdjustmentService $adjustments,
        private InventoryLocationService $locations,
        private Access $access,
    ) {}

    public function stock(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->access->allows($user, 'inventory.view') || $user->is_admin, 403);
        $store = $this->locations->ensureCentralStore();
        $this->access->abortUnlessCanAccessLocation($user, $store);

        return Inertia::render('Inventory/Stock', [
            'project' => null,
            'central' => true,
            'store' => $this->storeCard($store),
            'locations' => $this->adjustments->locationCards($user, null),
            'filters' => Listing::filters($request),
            'rows' => Listing::paginateRows($this->balances($request, $store), $request, [
                'material_code', 'material_name', 'unit', 'location', 'location_code', 'available', 'total_available',
                'total_received', 'total_issued', 'adjustments', 'status',
            ]),
            'canAdjust' => $this->access->allows($user, 'inventory.adjust') || $user->is_admin,
            'canImport' => $this->access->allows($user, 'inventory.import') || $user->is_admin,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($this->access->allows($user, 'inventory.view') || $user->is_admin, 403);
        $store = $this->locations->ensureCentralStore();
        $this->access->abortUnlessCanAccessLocation($user, $store);
        $rows = $this->balances($request, $store);

        return response()->streamDownload(function () use ($rows, $store): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Material code', 'Material name', 'Unit', 'Quantity', 'Store']);
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['material_code'],
                    $row['material_name'],
                    $row['unit'],
                    $row['available'],
                    $store->name,
                ]);
            }
            fclose($handle);
        }, 'central-store-stock.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function ledger(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->access->allows($user, 'inventory.view') || $user->is_admin, 403);
        $store = $this->locations->ensureCentralStore();
        $materialId = $request->integer('material_id') ?: null;
        $filters = Listing::filters($request);

        $query = StockLedgerEntry::query()
            ->whereNull('project_id')
            ->when($materialId, fn ($query) => $query->where('material_id', $materialId))
            ->where('location_id', $store->id)
            ->with(['material', 'location', 'postedBy'])
            ->orderBy('id');
        Listing::applySearch($query, $filters['search'], [
            'transaction_number', 'transaction_date', 'transaction_type', 'source_type', 'source_number',
            'stock_in', 'stock_out', 'balance', 'remarks',
            'material.name', 'material.material_code', 'location.name', 'postedBy.name',
        ]);

        return Inertia::render('Inventory/Ledger', [
            'project' => null,
            'central' => true,
            'store' => $this->storeCard($store),
            'materials' => Material::query()->orderBy('name')->get(['id', 'material_code', 'name']),
            'locations' => $this->adjustments->locationCards($user, null),
            'filters' => [...$filters, 'material_id' => $materialId],
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

    public function adjustments(Request $request): Response
    {
        $this->authorizeView($request);
        $store = $this->locations->ensureCentralStore();
        $filters = Listing::filters($request);
        $query = StockAdjustment::query()
            ->whereNull('project_id')
            ->where('location_id', $store->id)
            ->with(['material', 'location'])
            ->latest('adjustment_date')
            ->orderByDesc('id');
        Listing::applySearch($query, $filters['search'], [
            'adjustment_number', 'adjustment_date', 'adjustment_type', 'quantity', 'status',
            'material.name', 'material.material_code', 'location.name',
        ]);

        return Inertia::render('Inventory/Adjustments/Index', [
            'project' => null,
            'central' => true,
            'store' => $this->storeCard($store),
            'filters' => $filters,
            'adjustments' => Listing::paginate($query, $request)->through(fn (StockAdjustment $adjustment) => [
                'id' => $adjustment->id,
                'adjustment_number' => $adjustment->adjustment_number,
                'adjustment_date' => $adjustment->adjustment_date?->toDateString(),
                'material' => trim($adjustment->material?->material_code.' '.$adjustment->material?->name),
                'location' => $adjustment->location?->name,
                'adjustment_type' => $adjustment->adjustment_type,
                'quantity' => (string) $adjustment->quantity,
                'status' => $adjustment->status,
            ]),
        ]);
    }

    public function createAdjustment(Request $request): Response
    {
        abort_unless($this->access->allows($request->user(), 'inventory.adjust') || $request->user()->is_admin, 403);
        $store = $this->locations->ensureCentralStore();
        $locations = $this->adjustments->locationCards($request->user(), null);

        return Inertia::render('Inventory/Adjustments/Form', [
            'project' => null,
            'central' => true,
            'store' => $this->storeCard($store),
            'materials' => Material::query()->where('is_active', true)->orderBy('name')->get(['id', 'material_code', 'name', 'unit']),
            'locations' => $locations,
            'defaults' => [
                'location_id' => $store->id,
                'adjustment_type' => $request->string('type')->toString() ?: StockAdjustment::OPENING,
                'material_id' => $request->integer('material_id') ?: null,
            ],
        ]);
    }

    public function storeAdjustment(Request $request): RedirectResponse
    {
        abort_unless($this->access->allows($request->user(), 'inventory.adjust') || $request->user()->is_admin, 403);
        $store = $this->locations->ensureCentralStore();
        $validated = $request->validate([
            'material_id' => ['required', 'integer', 'exists:materials,id'],
            'location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'adjustment_date' => ['required', 'date'],
            'adjustment_type' => ['required', Rule::in([StockAdjustment::OPENING, StockAdjustment::INCREASE, StockAdjustment::DECREASE])],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reference_number' => ['nullable', 'string', 'max:80'],
            'reason' => ['nullable', 'string', 'max:5000'],
            'attachment' => array_merge(['nullable'], StoredUpload::rules(StoredUpload::DOCUMENTS, 5120)),
        ]);
        $validated['location_id'] = $store->id;

        if ($validated['adjustment_type'] !== StockAdjustment::OPENING && blank($validated['reason'] ?? null)) {
            return back()->withErrors(['reason' => 'Remarks are required for stock increases and decreases.'])->withInput();
        }

        $path = $request->hasFile('attachment')
            ? StoredUpload::store($request->file('attachment'), 'inventory/central/adjustments')
            : null;

        $adjustment = $this->adjustments->createDraft($request->user(), null, [
            ...$validated,
            'attachment_path' => $path,
        ]);

        return redirect()->route('inventory.central.adjustments.show', $adjustment)->with('success', 'Stock adjustment saved.');
    }

    public function showAdjustment(StockAdjustment $stockAdjustment): Response
    {
        $this->ensureCentral($stockAdjustment);
        $stockAdjustment->load(['material', 'location', 'creator', 'postedBy']);

        return Inertia::render('Inventory/Adjustments/Show', [
            'project' => null,
            'central' => true,
            'store' => $this->storeCard($stockAdjustment->location ?? $this->locations->ensureCentralStore()),
            'adjustment' => [
                'id' => $stockAdjustment->id,
                'adjustment_number' => $stockAdjustment->adjustment_number,
                'adjustment_date' => $stockAdjustment->adjustment_date?->toDateString(),
                'material' => trim($stockAdjustment->material?->material_code.' '.$stockAdjustment->material?->name),
                'unit' => $stockAdjustment->material?->unit,
                'location' => $stockAdjustment->location?->name,
                'location_code' => $stockAdjustment->location?->location_code,
                'adjustment_type' => $stockAdjustment->adjustment_type,
                'quantity' => (string) $stockAdjustment->quantity,
                'reference_number' => $stockAdjustment->reference_number,
                'reason' => $stockAdjustment->reason,
                'status' => $stockAdjustment->status,
                'created_by' => $stockAdjustment->creator?->name,
                'posted_by' => $stockAdjustment->postedBy?->name,
                'posted_at' => $stockAdjustment->posted_at?->toDateTimeString(),
                'has_attachment' => filled($stockAdjustment->attachment_path),
            ],
        ]);
    }

    public function attachment(StockAdjustment $stockAdjustment): StreamedResponse
    {
        $this->ensureCentral($stockAdjustment);
        abort_unless(filled($stockAdjustment->attachment_path), 404);
        abort_unless(Storage::disk('local')->exists($stockAdjustment->attachment_path), 404);

        return StoredUpload::serve(
            $stockAdjustment->attachment_path,
            inlineIfSafe: true,
            expectedPrefix: 'inventory/central/adjustments',
        );
    }

    public function postAdjustment(Request $request, StockAdjustment $stockAdjustment): RedirectResponse
    {
        abort_unless($this->access->allows($request->user(), 'inventory.adjust') || $request->user()->is_admin, 403);
        $this->ensureCentral($stockAdjustment);
        $this->adjustments->post($request->user(), null, $stockAdjustment);

        return back()->with('success', 'Stock adjustment posted.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function balances(Request $request, InventoryLocation $store): array
    {
        $locations = collect([$store]);
        $materialIds = StockLedgerEntry::query()
            ->whereNull('project_id')
            ->where('location_id', $store->id)
            ->distinct()
            ->pluck('material_id');

        $materials = Material::query()->whereIn('id', $materialIds)->orderBy('name')->get()->keyBy('id');
        $balances = $this->stock->projectBalances(null, $locations);
        $rows = [];

        foreach ($materialIds as $materialId) {
            $material = $materials->get($materialId);
            if (! $material) {
                continue;
            }

            $available = $balances['total'][(int) $materialId] ?? '0.000';
            $received = $balances['received'][(int) $materialId] ?? '0.000';
            $issued = $balances['issued'][(int) $materialId] ?? '0.000';
            $atLocation = $balances['at'][(int) $materialId][(int) $store->id] ?? '0.000';

            if (bccomp($atLocation, '0', 3) === 0) {
                continue;
            }

            $rows[] = [
                'material_id' => $material->id,
                'material_code' => $material->material_code,
                'material_name' => $material->name,
                'unit' => $material->unit,
                'location_id' => $store->id,
                'location' => $store->name,
                'location_code' => $store->location_code,
                'is_default_location' => false,
                'total_received' => $received,
                'total_issued' => $issued,
                'adjustments' => Money::sub(Money::add($available, $issued, 3), $received, 3),
                'available' => $atLocation,
                'total_available' => $available,
                'status' => bccomp($available, '0', 3) === 1 ? 'Available' : 'Out of Stock',
            ];
        }

        return $rows;
    }

    private function ensureCentral(StockAdjustment $adjustment): void
    {
        abort_unless($adjustment->project_id === null, 404);
        abort_unless($this->access->allows(request()->user(), 'inventory.view') || request()->user()?->is_admin, 403);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($this->access->allows($request->user(), 'inventory.view') || $request->user()->is_admin, 403);
    }

    /**
     * @return array{id: int, name: string, location_code: string}
     */
    private function storeCard(InventoryLocation $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'location_code' => $store->location_code,
        ];
    }
}
