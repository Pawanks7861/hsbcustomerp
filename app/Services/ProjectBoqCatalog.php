<?php

namespace App\Services;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\Project;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

class ProjectBoqCatalog
{
    public function __construct(private BoqProcurementQuantityService $quantities) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function selectable(Project $project): array
    {
        $project->loadMissing('boqs');
        $rows = [];

        foreach ($project->boqs as $boq) {
            $revisionId = $boq->procurementRevisionId();

            if (! $revisionId) {
                continue;
            }

            $items = BoqItem::query()
                ->where('boq_id', $boq->id)
                ->where('boq_revision_id', $revisionId)
                ->where('is_section', false)
                ->whereNotNull('quantity')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            $summaries = $this->quantities->summariesForItems($items);

            foreach ($items as $item) {
                $summary = $summaries[$item->id] ?? null;
                $rows[] = [
                    'id' => $item->id,
                    'boq_id' => $boq->id,
                    'label' => trim(($item->item_number ? $item->item_number.' ' : '').$item->description),
                    'description' => $item->description,
                    'service_code' => $item->service_code,
                    'unit' => $item->unit,
                    'quantity' => (string) $item->quantity,
                    'rate' => $item->rate === null ? null : (string) $item->rate,
                    'used_quantity' => $summary['used_quantity'] ?? '0.000',
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{item: BoqItem, lines: array<int, array<string, mixed>>}
     */
    public function lines(Project $project, array $rows): array
    {
        $lines = [];
        $first = null;

        foreach (array_values($rows) as $index => $row) {
            $item = BoqItem::query()->with('boq')->find($row['boq_item_id'] ?? null);
            $boq = $item?->boq;

            if (! $item || ! $boq instanceof Boq || (int) $boq->project_id !== (int) $project->id) {
                throw ValidationException::withMessages([
                    "items.$index.boq_item_id" => 'Select a BOQ item from this project.',
                ]);
            }

            if ($item->is_section || (int) $item->boq_revision_id !== (int) $boq->procurementRevisionId()) {
                throw ValidationException::withMessages([
                    "items.$index.boq_item_id" => 'Select an item from the current finalized BOQ revision.',
                ]);
            }

            if ($item->quantity === null) {
                throw ValidationException::withMessages([
                    "items.$index.boq_item_id" => 'This BOQ item does not have a quantity defined.',
                ]);
            }

            $quantity = Money::scale($row['quantity'] ?? null, 3);

            if ($quantity === null || bccomp($quantity, '0', 3) !== 1) {
                throw ValidationException::withMessages([
                    "items.$index.quantity" => 'Requested quantity must be greater than zero.',
                ]);
            }

            $name = trim((string) ($row['name'] ?? ''));
            $description = trim((string) ($row['description'] ?? ''));
            $unit = trim((string) ($row['unit'] ?? ''));
            $rate = Money::scale($item->rate, 2);
            $remarks = trim((string) ($row['remarks'] ?? ''));
            $lines[] = [
                'boq_item_id' => $item->id,
                'name' => $name !== '' ? $name : $item->description,
                'description' => $description !== '' ? $description : $item->description,
                'service_code' => $item->service_code,
                'unit' => $unit !== '' ? $unit : $item->unit,
                'quantity' => $quantity,
                'estimated_rate' => $rate,
                'estimated_amount' => Money::itemAmount($quantity, $rate),
                'remarks' => $remarks === '' ? null : $remarks,
            ];
            $first ??= $item;
        }

        return ['item' => $first, 'lines' => $lines];
    }
}
