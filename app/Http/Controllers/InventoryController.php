<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\MaterialIssue;
use App\Models\MaterialReceipt;
use App\Models\Project;
use App\Models\StockLedgerEntry;
use App\Services\InventoryStock;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function __construct(private InventoryStock $stock) {}

    public function dashboard(Request $request, Project $project): Response
    {
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        $balances = $this->balances($project);
        $withStock = 0;
        $low = 0;
        $out = 0;

        foreach ($balances as $row) {
            if (bccomp($row['available'], '0', 3) === 1) {
                $withStock++;
            }

            if ($row['status'] === 'Low Stock') {
                $low++;
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
                'low_stock' => $low,
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

    public function stock(Project $project): Response
    {
        return Inertia::render('Inventory/Stock', [
            'project' => $this->projectCard($project),
            'rows' => array_values($this->balances($project)),
        ]);
    }

    public function ledger(Request $request, Project $project): Response
    {
        $materialId = $request->integer('material_id') ?: null;

        $entries = StockLedgerEntry::query()
            ->where('project_id', $project->id)
            ->when($materialId, fn ($query) => $query->where('material_id', $materialId))
            ->with(['material', 'postedBy'])
            ->orderBy('id')
            ->get();

        return Inertia::render('Inventory/Ledger', [
            'project' => $this->projectCard($project),
            'materials' => Material::query()->orderBy('name')->get(['id', 'material_code', 'name']),
            'filters' => ['material_id' => $materialId],
            'entries' => $entries->map(fn (StockLedgerEntry $entry) => [
                'id' => $entry->id,
                'transaction_number' => $entry->transaction_number,
                'transaction_date' => $entry->transaction_date?->toDateString(),
                'material' => trim($entry->material?->material_code.' '.$entry->material?->name),
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
     * @return array<int, array<string, mixed>>
     */
    private function balances(Project $project): array
    {
        $materialIds = StockLedgerEntry::query()
            ->where('project_id', $project->id)
            ->distinct()
            ->pluck('material_id');

        $materials = Material::query()->whereIn('id', $materialIds)->orderBy('name')->get()->keyBy('id');
        $rows = [];

        foreach ($materialIds as $materialId) {
            $material = $materials->get($materialId);

            if (! $material) {
                continue;
            }

            $available = $this->stock->balance($project->id, (int) $materialId);
            $received = Money::scale((string) StockLedgerEntry::query()->where('project_id', $project->id)->where('material_id', $materialId)->where('transaction_type', StockLedgerEntry::RECEIPT)->sum('stock_in'), 3) ?? '0.000';
            $issued = Money::scale((string) StockLedgerEntry::query()->where('project_id', $project->id)->where('material_id', $materialId)->where('transaction_type', StockLedgerEntry::ISSUE)->sum('stock_out'), 3) ?? '0.000';
            $minimum = Money::scale($material->minimum_stock, 3) ?? '0.000';

            $rows[(int) $materialId] = [
                'material_id' => $material->id,
                'material_code' => $material->material_code,
                'material_name' => $material->name,
                'unit' => $material->unit,
                'total_received' => $received,
                'total_issued' => $issued,
                'adjustments' => Money::sub(Money::add($available, $issued, 3), $received, 3),
                'available' => $available,
                'minimum_stock' => $minimum,
                'status' => $this->status($available, $minimum),
            ];
        }

        return $rows;
    }

    private function status(string $available, string $minimum): string
    {
        if (bccomp($available, '0', 3) !== 1) {
            return 'Out of Stock';
        }

        if (bccomp($minimum, '0', 3) === 1 && bccomp($available, $minimum, 3) === -1) {
            return 'Low Stock';
        }

        return 'Available';
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
