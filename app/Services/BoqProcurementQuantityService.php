<?php

namespace App\Services;

use App\Models\BoqItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Support\DocumentStatus;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BoqProcurementQuantityService
{
    /**
     * @return array{
     *     boq_quantity: ?string,
     *     unit: ?string,
     *     submitted_quantity: string,
     *     approved_quantity: string,
     *     used_quantity: string,
     *     remaining_quantity: ?string,
     *     has_quantity: bool
     * }
     */
    public function getSummary(BoqItem $boqItem, ?PurchaseRequest $excludePr = null): array
    {
        return $this->fromRows($boqItem, $this->countedRows($boqItem->id, $excludePr));
    }

    /**
     * @param  iterable<int, BoqItem>  $items
     * @return array<int, array<string, mixed>>
     */
    public function summariesForItems(iterable $items): array
    {
        $items = collect($items)->filter(fn ($item) => $item instanceof BoqItem && $item->id)->keyBy('id');

        if ($items->isEmpty()) {
            return [];
        }

        $lineRows = PurchaseRequestItem::query()
            ->whereIn('boq_item_id', $items->keys()->all())
            ->whereHas('purchaseRequest', fn ($query) => $query->whereIn('status', [DocumentStatus::SUBMITTED, DocumentStatus::APPROVED]))
            ->with('purchaseRequest:id,status')
            ->get(['id', 'purchase_request_id', 'boq_item_id', 'quantity']);

        $grouped = PurchaseRequest::query()
            ->whereIn('boq_item_id', $items->keys()->all())
            ->whereIn('status', [DocumentStatus::SUBMITTED, DocumentStatus::APPROVED])
            ->when($lineRows->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $lineRows->pluck('purchase_request_id')->unique()->all()))
            ->get(['boq_item_id', 'status', 'requested_boq_quantity'])
            ->groupBy('boq_item_id');

        foreach ($lineRows as $line) {
            $bucket = $grouped->get($line->boq_item_id, collect());
            $bucket->push((object) [
                'status' => $line->purchaseRequest->status,
                'requested_boq_quantity' => $line->quantity,
            ]);
            $grouped->put($line->boq_item_id, $bucket);
        }

        $summaries = [];

        foreach ($items as $id => $item) {
            $summaries[$id] = $this->fromRows($item, $grouped->get($id, collect()));
        }

        return $summaries;
    }

    /**
     * @return array{
     *     boq_quantity: ?string,
     *     previous_requested_quantity: string,
     *     requested_quantity: string,
     *     cumulative_quantity: string,
     *     remaining_before_request: ?string,
     *     excess_quantity: string,
     *     is_exceeded: bool,
     *     has_quantity: bool,
     *     unit: ?string
     * }
     */
    public function checkRequest(BoqItem $boqItem, string $requestedQty, ?PurchaseRequest $excludePr = null): array
    {
        $summary = $this->getSummary($boqItem, $excludePr);
        $requested = $this->quantity($requestedQty);
        $previous = $summary['used_quantity'];
        $cumulative = Money::add($previous, $requested, 3);
        $boqQuantity = $summary['boq_quantity'];
        $exceeded = $boqQuantity !== null && bccomp($cumulative, $boqQuantity, 3) === 1;

        return [
            'boq_quantity' => $boqQuantity,
            'previous_requested_quantity' => $previous,
            'requested_quantity' => $requested,
            'cumulative_quantity' => $cumulative,
            'remaining_before_request' => $boqQuantity === null ? null : bcsub($boqQuantity, $previous, 3),
            'excess_quantity' => $exceeded ? bcsub($cumulative, $boqQuantity, 3) : '0.000',
            'is_exceeded' => $exceeded,
            'has_quantity' => $summary['has_quantity'],
            'unit' => $summary['unit'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $summary
     * @return array{state: string, excess_quantity: ?string, unit: ?string}
     */
    public function displayFor(PurchaseRequest $record, ?array $summary = null): array
    {
        $record->loadMissing('boqItem');
        $item = $record->boqItem;
        $summary ??= $item ? $this->getSummary($item) : null;
        $unit = $item?->unit;

        if ($summary === null || $summary['boq_quantity'] === null) {
            return [
                'state' => 'undefined',
                'excess_quantity' => null,
                'unit' => $unit,
            ];
        }

        if ($record->status === DocumentStatus::REJECTED) {
            return [
                'state' => 'released',
                'excess_quantity' => null,
                'unit' => $unit,
            ];
        }

        if (in_array($record->status, [DocumentStatus::SUBMITTED, DocumentStatus::APPROVED], true)
            && $record->boq_quantity_at_submission !== null) {
            return [
                'state' => $record->is_boq_quantity_exceeded ? 'exceeded' : 'within',
                'excess_quantity' => (string) $record->excess_quantity_at_submission,
                'unit' => $unit,
            ];
        }

        $requested = $this->quantity($record->requested_boq_quantity ?? '0');
        $cumulative = Money::add($summary['used_quantity'], $requested, 3);
        $exceeded = bccomp($cumulative, $summary['boq_quantity'], 3) === 1;

        return [
            'state' => $exceeded ? 'exceeded' : 'within',
            'excess_quantity' => $exceeded ? bcsub($cumulative, $summary['boq_quantity'], 3) : '0.000',
            'unit' => $unit,
        ];
    }

    public function submit(PurchaseRequest $purchaseRequest): PurchaseRequest
    {
        return DB::transaction(function () use ($purchaseRequest) {
            $purchaseRequest->load('items');
            $linked = $purchaseRequest->items->filter(fn (PurchaseRequestItem $line) => $line->boq_item_id !== null)->values();

            if ($linked->isEmpty()) {
                return $this->submitHeader($purchaseRequest);
            }

            $locked = [];

            foreach ($linked->pluck('boq_item_id')->map(fn ($id) => (int) $id)->unique()->sort()->values() as $id) {
                $locked[$id] = $this->lockItemForUpdate($id);
            }

            $purchaseRequest->refresh();
            $purchaseRequest->load('items');
            abort_unless($purchaseRequest->status === DocumentStatus::DRAFT, 403);

            $linked = $purchaseRequest->items->filter(fn (PurchaseRequestItem $line) => $line->boq_item_id !== null)->values();
            $headerCheck = null;
            $anyExceeded = false;
            $excess = '0.000';

            foreach ($linked as $index => $line) {
                $item = $locked[(int) $line->boq_item_id];
                $requested = Money::scale($line->quantity, 3);

                if ($item->quantity === null || $requested === null || bccomp($requested, '0', 3) !== 1) {
                    throw ValidationException::withMessages([
                        "items.$index.quantity" => 'Requested quantity must be greater than zero.',
                    ]);
                }

                $check = $this->checkRequest($item, $requested, $purchaseRequest);
                $line->update([
                    'is_boq_quantity_exceeded' => $check['is_exceeded'],
                    'boq_quantity_at_submission' => $check['boq_quantity'],
                    'previous_requested_quantity' => $check['previous_requested_quantity'],
                    'excess_quantity' => $check['excess_quantity'],
                ]);

                if ($check['is_exceeded']) {
                    $anyExceeded = true;
                    $excess = Money::add($excess, $check['excess_quantity'], 3);
                }

                if ($headerCheck === null || ($check['is_exceeded'] && ! $headerCheck['is_exceeded'])) {
                    $headerCheck = $check;
                }
            }

            $purchaseRequest->update([
                'status' => DocumentStatus::SUBMITTED,
                'submitted_at' => now(),
                'rejection_reason' => null,
                'rejected_at' => null,
                'rejected_by' => null,
                'is_boq_quantity_exceeded' => $anyExceeded,
                'boq_quantity_at_submission' => $headerCheck['boq_quantity'],
                'previous_requested_quantity_at_submission' => $headerCheck['previous_requested_quantity'],
                'excess_quantity_at_submission' => $excess,
            ]);

            return $purchaseRequest;
        });
    }

    private function submitHeader(PurchaseRequest $purchaseRequest): PurchaseRequest
    {
        $item = $this->lockItemForUpdate($purchaseRequest->boq_item_id);
        $purchaseRequest->refresh();

        abort_unless($purchaseRequest->status === DocumentStatus::DRAFT, 403);

        if ($item->quantity === null) {
            throw ValidationException::withMessages([
                'requested_boq_quantity' => 'This BOQ item does not have a quantity defined.',
            ]);
        }

        $requested = Money::scale($purchaseRequest->requested_boq_quantity, 3);

        if ($requested === null || bccomp($requested, '0', 3) !== 1) {
            throw ValidationException::withMessages([
                'requested_boq_quantity' => 'Requested BOQ quantity is required.',
            ]);
        }

        $check = $this->checkRequest($item, $requested, $purchaseRequest);

        $purchaseRequest->update([
            'status' => DocumentStatus::SUBMITTED,
            'submitted_at' => now(),
            'rejection_reason' => null,
            'rejected_at' => null,
            'rejected_by' => null,
            'is_boq_quantity_exceeded' => $check['is_exceeded'],
            'boq_quantity_at_submission' => $check['boq_quantity'],
            'previous_requested_quantity_at_submission' => $check['previous_requested_quantity'],
            'excess_quantity_at_submission' => $check['excess_quantity'],
        ]);

        return $purchaseRequest;
    }

    public function lockItemForUpdate(int $boqItemId): BoqItem
    {
        return BoqItem::query()->whereKey($boqItemId)->lockForUpdate()->firstOrFail();
    }

    private function countedRows(int $boqItemId, ?PurchaseRequest $excludePr): Collection
    {
        $lines = PurchaseRequestItem::query()
            ->where('boq_item_id', $boqItemId)
            ->whereHas('purchaseRequest', function ($query) use ($excludePr) {
                $query->whereIn('status', [DocumentStatus::SUBMITTED, DocumentStatus::APPROVED]);

                if ($excludePr) {
                    $query->whereKeyNot($excludePr->id);
                }
            })
            ->with('purchaseRequest:id,status')
            ->get();

        $legacy = PurchaseRequest::query()
            ->where('boq_item_id', $boqItemId)
            ->whereIn('status', [DocumentStatus::SUBMITTED, DocumentStatus::APPROVED])
            ->when($excludePr, fn ($query) => $query->whereKeyNot($excludePr->id))
            ->whereDoesntHave('items', fn ($items) => $items->whereNotNull('boq_item_id'))
            ->get(['status', 'requested_boq_quantity']);

        return $legacy->concat($lines->map(fn (PurchaseRequestItem $line) => (object) [
            'status' => $line->purchaseRequest->status,
            'requested_boq_quantity' => $line->quantity,
        ]));
    }

    /**
     * @param  Collection<int, PurchaseRequest>  $rows
     * @return array<string, mixed>
     */
    private function fromRows(BoqItem $boqItem, Collection $rows): array
    {
        $submitted = '0.000';
        $approved = '0.000';

        foreach ($rows as $row) {
            $quantity = $this->quantity($row->requested_boq_quantity);

            if ($row->status === DocumentStatus::APPROVED) {
                $approved = Money::add($approved, $quantity, 3);
            } else {
                $submitted = Money::add($submitted, $quantity, 3);
            }
        }

        $used = Money::add($submitted, $approved, 3);
        $boqQuantity = $boqItem->quantity === null ? null : $this->quantity($boqItem->quantity);

        return [
            'boq_quantity' => $boqQuantity,
            'unit' => $boqItem->unit,
            'submitted_quantity' => $submitted,
            'approved_quantity' => $approved,
            'used_quantity' => $used,
            'remaining_quantity' => $boqQuantity === null ? null : bcsub($boqQuantity, $used, 3),
            'has_quantity' => $boqQuantity !== null,
        ];
    }

    private function quantity(mixed $value): string
    {
        return Money::scale($value ?? '0', 3) ?? '0.000';
    }
}
