<?php

namespace App\Services;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseRequestLineComposer
{
    public function __construct(private PurchaseRequestPoSyncService $sync) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{item: ?BoqItem, lines: array<int, array<string, mixed>>}
     */
    public function compose(Project $project, array $rows): array
    {
        $lines = [];
        $first = null;
        $seen = [];

        foreach (array_values($rows) as $index => $row) {
            $tagId = $this->nullableId($row['boq_item_id'] ?? null);
            $item = null;

            if ($tagId !== null) {
                if (isset($seen[$tagId])) {
                    throw ValidationException::withMessages([
                        "items.$index.boq_item_id" => 'Each BOQ item can only be tagged once on a purchase request.',
                    ]);
                }

                $seen[$tagId] = true;
                $item = $this->resolveTag($project, $tagId, $index);
                $first ??= $item;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $description = trim((string) ($row['description'] ?? ''));
            $unit = trim((string) ($row['unit'] ?? ''));

            if ($name === '' && $item) {
                $name = trim((string) $item->description);
            }

            if ($description === '' && $item) {
                $description = trim((string) $item->description);
            }

            if ($unit === '' && $item) {
                $unit = trim((string) ($item->unit ?? ''));
            }

            if ($name === '') {
                throw ValidationException::withMessages([
                    "items.$index.name" => 'Item name is required.',
                ]);
            }

            $quantity = Money::scale($row['quantity'] ?? null, 3);

            if ($quantity === null || bccomp($quantity, '0', 3) !== 1) {
                throw ValidationException::withMessages([
                    "items.$index.quantity" => 'Requested quantity must be greater than zero.',
                ]);
            }

            $postedRate = array_key_exists('estimated_rate', $row) && $row['estimated_rate'] !== null && $row['estimated_rate'] !== ''
                ? Money::scale($row['estimated_rate'], 2)
                : null;
            $boqRate = $item ? Money::scale($item->rate, 2) : null;
            $rate = $postedRate ?? $boqRate;
            $remarks = trim((string) ($row['remarks'] ?? ''));
            $serviceCode = trim((string) ($row['service_code'] ?? ($item?->service_code ?? '')));

            $lines[] = [
                'id' => $this->nullableId($row['id'] ?? null),
                'boq_item_id' => $item?->id,
                'name' => $name,
                'description' => $description === '' ? $name : $description,
                'service_code' => $serviceCode === '' ? null : $serviceCode,
                'unit' => $unit === '' ? null : $unit,
                'quantity' => $quantity,
                'estimated_rate' => $rate,
                'estimated_amount' => Money::itemAmount($quantity, $rate),
                'remarks' => $remarks === '' ? null : $remarks,
            ];
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'items' => 'Add at least one item.',
            ]);
        }

        return ['item' => $first, 'lines' => $lines];
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function save(PurchaseRequest $purchaseRequest, array $header, array $lines): PurchaseRequest
    {
        return DB::transaction(function () use ($purchaseRequest, $header, $lines) {
            $purchaseRequest->update($header);
            $kept = $this->upsertLines($purchaseRequest, $lines);
            $removing = $purchaseRequest->items()->whereNotIn('id', $kept)->get();
            $this->sync->synchronize($purchaseRequest->fresh('items'), $removing);
            $removing->each->delete();

            return $purchaseRequest->fresh('items');
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<int>
     */
    private function upsertLines(PurchaseRequest $purchaseRequest, array $lines): array
    {
        $existing = $purchaseRequest->items()->get()->keyBy('id');
        $kept = [];

        foreach ($lines as $line) {
            $id = $line['id'] ?? null;
            unset($line['id']);

            if ($id && $existing->has($id)) {
                $existing->get($id)->update($line);
                $kept[] = (int) $id;
                continue;
            }

            $created = $purchaseRequest->items()->create($line);
            $kept[] = (int) $created->id;
        }

        return $kept;
    }

    private function resolveTag(Project $project, int $boqItemId, int $index): BoqItem
    {
        $item = BoqItem::query()->with('boq')->find($boqItemId);
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

        return $item;
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value ?: null;
    }
}
