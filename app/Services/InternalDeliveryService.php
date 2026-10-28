<?php

namespace App\Services;

use App\Models\InternalDeliveryNote;
use App\Models\InternalDeliveryNoteItem;
use App\Models\InternalDeliveryReceipt;
use App\Models\InventoryLocation;
use App\Models\Material;
use App\Models\StockLedgerEntry;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InternalDeliveryService
{
    public function __construct(
        private InventoryStock $stock,
        private InventoryLocationService $locations,
        private ActivityLogger $activity,
    ) {}

    /**
     * @param  list<array{material_id: int, quantity: mixed, remarks?: string|null}>  $rows
     */
    public function save(?InternalDeliveryNote $note, array $header, array $rows, User $user): InternalDeliveryNote
    {
        return DB::transaction(function () use ($note, $header, $rows, $user) {
            if ($note) {
                $note = InternalDeliveryNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();
                abort_unless($note->isEditable(), 403);
            }

            $source = $this->selectableLocation((int) $header['source_location_id']);
            $destination = $this->selectableLocation((int) $header['destination_location_id']);

            if ($source->id === $destination->id) {
                throw ValidationException::withMessages([
                    'destination_location_id' => 'Source and destination locations must be different.',
                ]);
            }

            $lines = $this->lines($rows);

            $attributes = [
                'transfer_date' => $header['transfer_date'],
                'source_location_id' => $source->id,
                'destination_location_id' => $destination->id,
                'source_project_id' => $source->project_id,
                'destination_project_id' => $destination->project_id,
                'expected_arrival_date' => $header['expected_arrival_date'] ?? null,
                'vehicle_number' => $header['vehicle_number'] ?? null,
                'driver_name' => $header['driver_name'] ?? null,
                'remarks' => $header['remarks'] ?? null,
                'requested_by' => $note?->requested_by ?: $user->id,
            ];

            if (! $note) {
                $note = InternalDeliveryNote::query()->create([
                    ...$attributes,
                    'idn_number' => DocumentNumber::next(InternalDeliveryNote::class, 'idn_number', 'IDN'),
                    'status' => InternalDeliveryNote::DRAFT,
                ]);
                $this->activity->log($user->id, 'idn.created', $note);
            } else {
                $note->update($attributes);
                $note->items()->delete();
                $this->activity->log($user->id, 'idn.updated', $note);
            }

            $note->items()->createMany($lines);

            return $note->fresh('items');
        });
    }

    public function submit(InternalDeliveryNote $note, User $user): void
    {
        DB::transaction(function () use ($note, $user): void {
            $locked = $this->lock($note);
            abort_unless($locked->status === InternalDeliveryNote::DRAFT, 403);
            abort_unless($locked->items()->exists(), 422);
            $locked->update([
                'status' => InternalDeliveryNote::SUBMITTED,
                'submitted_at' => now(),
                'submitted_by' => $user->id,
            ]);
            $this->activity->log($user->id, 'idn.submitted', $locked);
        });
    }

    public function approve(InternalDeliveryNote $note, User $user): void
    {
        DB::transaction(function () use ($note, $user): void {
            $locked = $this->lock($note);
            abort_unless($locked->status === InternalDeliveryNote::SUBMITTED, 403);
            $locked->update([
                'status' => InternalDeliveryNote::APPROVED,
                'approved_at' => now(),
                'approved_by' => $user->id,
            ]);
            $this->activity->log($user->id, 'idn.approved', $locked);
        });
    }

    public function reject(InternalDeliveryNote $note, User $user, string $reason): void
    {
        DB::transaction(function () use ($note, $user, $reason): void {
            $locked = $this->lock($note);
            abort_unless($locked->status === InternalDeliveryNote::SUBMITTED, 403);
            $locked->update([
                'status' => InternalDeliveryNote::REJECTED,
                'rejected_at' => now(),
                'rejected_by' => $user->id,
                'rejection_reason' => $reason,
            ]);
            $this->activity->log($user->id, 'idn.rejected', $locked, ['reason' => $reason]);
        });
    }

    public function dispatch(InternalDeliveryNote $note, User $user): void
    {
        DB::transaction(function () use ($note, $user): void {
            $locked = $this->lock($note);
            abort_unless($locked->status === InternalDeliveryNote::APPROVED, 403);
            $locked->load('items', 'sourceLocation');
            $transit = $this->locations->ensureInTransit();
            $projectId = $this->ledgerProjectId($locked->source_project_id, $locked->sourceLocation);

            $materialIds = $locked->items->pluck('material_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();

            foreach ($materialIds as $materialId) {
                Material::query()->whereKey($materialId)->lockForUpdate()->first();
            }

            foreach ($locked->items as $item) {
                $available = $this->stock->locationBalance($projectId, (int) $item->material_id, (int) $locked->source_location_id);

                if (bccomp((string) $item->quantity, $available, 3) === 1) {
                    throw ValidationException::withMessages([
                        'items' => 'Transfer quantity exceeds available stock at the source location.',
                    ]);
                }
            }

            foreach ($locked->items as $item) {
                $quantity = Money::scale((string) $item->quantity, 3) ?? '0.000';
                $outbound = $this->stock->move(
                    $projectId,
                    (int) $item->material_id,
                    $locked->transfer_date->toDateString(),
                    StockLedgerEntry::TRANSFER_OUT,
                    'idn_dispatch',
                    (int) $locked->id,
                    (int) $item->id,
                    $locked->idn_number,
                    '0',
                    $quantity,
                    $user->id,
                    'Dispatch '.$locked->idn_number,
                    (int) $locked->source_location_id,
                );
                $item->update([
                    'unit_cost' => $outbound->unit_cost,
                    'amount' => $outbound->amount,
                    'is_estimated' => (bool) $outbound->is_estimated,
                ]);
                $this->stock->move(
                    $projectId,
                    (int) $item->material_id,
                    $locked->transfer_date->toDateString(),
                    StockLedgerEntry::IN_TRANSIT,
                    'idn_transit',
                    (int) $locked->id,
                    (int) $item->id,
                    $locked->idn_number,
                    $quantity,
                    '0',
                    $user->id,
                    'In transit '.$locked->idn_number,
                    (int) $transit->id,
                );
            }

            $locked->update([
                'status' => InternalDeliveryNote::DISPATCHED,
                'dispatched_at' => now(),
                'dispatched_by' => $user->id,
            ]);
            $this->activity->log($user->id, 'idn.dispatched', $locked);
        });
    }

    /**
     * @param  list<array{item_id: int, quantity_received: mixed, quantity_damaged: mixed, quantity_short: mixed, remarks?: string|null}>  $rows
     */
    public function receive(InternalDeliveryNote $note, array $rows, User $user, ?string $remarks): InternalDeliveryReceipt
    {
        return DB::transaction(function () use ($note, $rows, $user, $remarks) {
            $locked = $this->lock($note);
            abort_unless($locked->isOpenForReceipt(), 403);
            $locked->load('items.material', 'destinationLocation', 'sourceLocation');
            $transit = $this->locations->ensureInTransit();
            $sourceProjectId = $this->ledgerProjectId($locked->source_project_id, $locked->sourceLocation);
            $destinationProjectId = $this->ledgerProjectId($locked->destination_project_id, $locked->destinationLocation);

            $byId = $locked->items->keyBy('id');
            $prepared = [];

            foreach ($rows as $row) {
                $item = $byId->get((int) ($row['item_id'] ?? 0));

                if (! $item) {
                    throw ValidationException::withMessages(['items' => 'A receipt line does not belong to this delivery note.']);
                }

                $received = Money::scale($row['quantity_received'] ?? 0, 3) ?? '0.000';
                $damaged = Money::scale($row['quantity_damaged'] ?? 0, 3) ?? '0.000';
                $short = Money::scale($row['quantity_short'] ?? 0, 3) ?? '0.000';

                foreach (['quantity_received' => $received, 'quantity_damaged' => $damaged, 'quantity_short' => $short] as $field => $value) {
                    if (bccomp($value, '0', 3) === -1) {
                        throw ValidationException::withMessages([$field => 'Quantities cannot be negative.']);
                    }
                }

                $accounted = Money::add(Money::add($received, $damaged, 3), $short, 3);

                if (bccomp($accounted, '0', 3) !== 1) {
                    continue;
                }

                $remaining = $this->remaining($item);

                if (bccomp($accounted, $remaining, 3) === 1) {
                    throw ValidationException::withMessages([
                        'items' => 'Received, damaged, and short quantities cannot exceed the quantity still in transit.',
                    ]);
                }

                $prepared[] = compact('item', 'received', 'damaged', 'short', 'accounted') + [
                    'remarks' => $row['remarks'] ?? null,
                ];
            }

            if ($prepared === []) {
                throw ValidationException::withMessages(['items' => 'Enter a received, damaged, or short quantity.']);
            }

            $receipt = $locked->receipts()->create([
                'received_at' => now(),
                'received_by' => $user->id,
                'remarks' => $remarks,
            ]);

            foreach ($prepared as $line) {
                /** @var InternalDeliveryNoteItem $item */
                $item = $line['item'];
                $receiptItem = $receipt->items()->create([
                    'internal_delivery_note_item_id' => $item->id,
                    'quantity_received' => $line['received'],
                    'quantity_damaged' => $line['damaged'],
                    'quantity_short' => $line['short'],
                    'remarks' => $line['remarks'],
                ]);

                if (bccomp($line['received'], '0', 3) === 1) {
                    $this->stock->move(
                        $sourceProjectId,
                        (int) $item->material_id,
                        now()->toDateString(),
                        StockLedgerEntry::IN_TRANSIT,
                        'idn_transit_out',
                        (int) $locked->id,
                        (int) $receiptItem->id,
                        $locked->idn_number,
                        '0',
                        $line['received'],
                        $user->id,
                        'Received from transit '.$locked->idn_number,
                        (int) $transit->id,
                    );
                    $this->stock->move(
                        $destinationProjectId,
                        (int) $item->material_id,
                        now()->toDateString(),
                        StockLedgerEntry::TRANSFER_IN,
                        'idn_receive',
                        (int) $locked->id,
                        (int) $receiptItem->id,
                        $locked->idn_number,
                        $line['received'],
                        '0',
                        $user->id,
                        'Received '.$locked->idn_number,
                        (int) $locked->destination_location_id,
                    );
                }

                if (bccomp($line['damaged'], '0', 3) === 1) {
                    $this->clearTransit($locked, $item, $transit, $sourceProjectId, $line['damaged'], 'idn_damage', (int) $receiptItem->id, $user, 'Damaged '.$locked->idn_number);
                }

                if (bccomp($line['short'], '0', 3) === 1) {
                    $this->clearTransit($locked, $item, $transit, $sourceProjectId, $line['short'], 'idn_short', (int) $receiptItem->id, $user, 'Short '.$locked->idn_number);
                }

                $item->update([
                    'quantity_accepted' => Money::add((string) $item->quantity_accepted, $line['received'], 3),
                    'quantity_damaged' => Money::add((string) $item->quantity_damaged, $line['damaged'], 3),
                    'quantity_short' => Money::add((string) $item->quantity_short, $line['short'], 3),
                ]);
            }

            $locked->load('items');
            $open = $locked->items->contains(fn (InternalDeliveryNoteItem $item) => bccomp($this->remaining($item), '0', 3) === 1);
            $locked->update([
                'status' => $open ? InternalDeliveryNote::PARTIAL : InternalDeliveryNote::RECEIVED,
                'received_at' => $open ? $locked->received_at : now(),
                'received_by' => $open ? $locked->received_by : $user->id,
            ]);
            $this->activity->log($user->id, $open ? 'idn.partially_received' : 'idn.received', $locked, [
                'receipt_id' => $receipt->id,
            ]);

            return $receipt;
        });
    }

    public function cancel(InternalDeliveryNote $note, User $user, string $reason): void
    {
        DB::transaction(function () use ($note, $user, $reason): void {
            $locked = $this->lock($note);

            if (in_array($locked->status, [InternalDeliveryNote::DRAFT, InternalDeliveryNote::SUBMITTED, InternalDeliveryNote::APPROVED, InternalDeliveryNote::REJECTED], true)) {
                $locked->update([
                    'status' => InternalDeliveryNote::CANCELLED,
                    'cancelled_at' => now(),
                    'cancelled_by' => $user->id,
                    'cancellation_reason' => $reason,
                ]);
                $this->activity->log($user->id, 'idn.cancelled', $locked, ['reason' => $reason]);

                return;
            }

            abort_unless($locked->status === InternalDeliveryNote::DISPATCHED, 403);
            abort_if($locked->receipts()->exists(), 403);

            $entries = StockLedgerEntry::query()
                ->where('source_id', $locked->id)
                ->whereIn('source_type', ['idn_dispatch', 'idn_transit'])
                ->orderBy('id')
                ->get();

            foreach ($entries as $entry) {
                $this->stock->move(
                    $entry->project_id !== null ? (int) $entry->project_id : null,
                    (int) $entry->material_id,
                    now()->toDateString(),
                    StockLedgerEntry::REVERSAL,
                    'reversal',
                    (int) $locked->id,
                    (int) $entry->id,
                    $locked->idn_number,
                    (string) $entry->stock_out,
                    (string) $entry->stock_in,
                    $user->id,
                    'Reversal of '.$locked->idn_number,
                    $entry->location_id ? (int) $entry->location_id : null,
                );
            }

            $locked->update([
                'status' => InternalDeliveryNote::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancellation_reason' => $reason,
            ]);
            $this->activity->log($user->id, 'idn.cancelled', $locked, ['reason' => $reason, 'reversed' => $entries->count()]);
        });
    }

    private function ledgerProjectId(mixed $noteProjectId, ?InventoryLocation $location): ?int
    {
        if ($noteProjectId) {
            return (int) $noteProjectId;
        }

        return $location?->project_id ? (int) $location->project_id : null;
    }

    private function clearTransit(InternalDeliveryNote $note, InternalDeliveryNoteItem $item, InventoryLocation $transit, ?int $projectId, string $quantity, string $sourceType, int $sourceItemId, User $user, string $remarks): void
    {
        $this->stock->move(
            $projectId,
            (int) $item->material_id,
            now()->toDateString(),
            StockLedgerEntry::DISCREPANCY,
            $sourceType,
            (int) $note->id,
            $sourceItemId,
            $note->idn_number,
            '0',
            $quantity,
            $user->id,
            $remarks,
            (int) $transit->id,
        );
    }

    private function remaining(InternalDeliveryNoteItem $item): string
    {
        $accounted = Money::add(
            Money::add((string) $item->quantity_accepted, (string) $item->quantity_damaged, 3),
            (string) $item->quantity_short,
            3
        );
        $left = Money::sub(Money::scale((string) $item->quantity, 3) ?? '0.000', $accounted, 3);

        return bccomp($left, '0', 3) === -1 ? '0.000' : $left;
    }

    private function lock(InternalDeliveryNote $note): InternalDeliveryNote
    {
        return InternalDeliveryNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();
    }

    private function selectableLocation(int $id): InventoryLocation
    {
        $location = InventoryLocation::query()->whereKey($id)->where('is_active', true)->first();

        if (! $location || $location->isInTransit() || $location->is_system) {
            throw ValidationException::withMessages([
                'source_location_id' => 'Choose an active location.',
            ]);
        }

        return $location;
    }

    /**
     * @param  list<array{material_id: int, quantity: mixed, remarks?: string|null}>  $rows
     * @return list<array<string, mixed>>
     */
    private function lines(array $rows): array
    {
        if ($rows === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one material.']);
        }

        $seen = [];
        $lines = [];

        foreach ($rows as $index => $row) {
            $material = Material::query()->whereKey($row['material_id'] ?? 0)->where('is_active', true)->first();

            if (! $material) {
                throw ValidationException::withMessages(["items.$index.material_id" => 'Select an active inventory material.']);
            }

            if (isset($seen[$material->id])) {
                throw ValidationException::withMessages(["items.$index.material_id" => 'This material is already on the delivery note.']);
            }

            $seen[$material->id] = true;
            $quantity = Money::scale($row['quantity'] ?? null, 3);

            if ($quantity === null || bccomp($quantity, '0', 3) !== 1) {
                throw ValidationException::withMessages(["items.$index.quantity" => 'Transfer quantity must be greater than zero.']);
            }

            $lines[] = [
                'material_id' => $material->id,
                'quantity' => $quantity,
                'unit' => $material->unit,
                'remarks' => $row['remarks'] ?? null,
            ];
        }

        return $lines;
    }
}
