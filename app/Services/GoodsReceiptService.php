<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialReceipt;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockLedgerEntry;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsReceiptService
{
    public function __construct(
        private PoReceiptProgress $progress,
        private InventoryStock $stock,
    ) {}

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function save(Project $project, PurchaseOrder $order, ?MaterialReceipt $receipt, array $header, array $rows, int $userId): MaterialReceipt
    {
        $this->guardOrder($project, $order);

        if ($receipt) {
            abort_unless($receipt->isGrn() && $receipt->status === MaterialReceipt::STATUS_DRAFT, 403);
            abort_unless((int) $receipt->purchase_order_id === (int) $order->id, 404);
        }

        return DB::transaction(function () use ($project, $order, $receipt, $header, $rows, $userId) {
            $order->load('items');
            $this->lockOrderItems($order);
            $lines = $this->lines($order, $rows, $receipt?->id);

            if ($receipt) {
                $receipt->update($header);
                $receipt->items()->delete();
            } else {
                $receipt = MaterialReceipt::query()->create([
                    ...$header,
                    'project_id' => $project->id,
                    'purchase_order_id' => $order->id,
                    'vendor_id' => $order->vendor_id,
                    'receipt_number' => DocumentNumber::next(MaterialReceipt::class, 'receipt_number', 'GRN'),
                    'document_kind' => MaterialReceipt::KIND_GRN,
                    'status' => MaterialReceipt::STATUS_DRAFT,
                    'created_by' => $userId,
                ]);
            }

            $receipt->items()->createMany($lines);

            return $receipt->fresh('items');
        });
    }

    public function submit(MaterialReceipt $receipt, int $userId): void
    {
        DB::transaction(function () use ($receipt, $userId): void {
            $locked = $this->lockReceipt($receipt);
            abort_unless($locked->status === MaterialReceipt::STATUS_DRAFT, 403);
            $this->assertFits($locked);
            $locked->update([
                'status' => MaterialReceipt::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'submitted_by' => $userId,
                'rejection_reason' => null,
                'rejected_at' => null,
                'rejected_by' => null,
            ]);
        });
    }

    public function post(MaterialReceipt $receipt, int $userId): void
    {
        DB::transaction(function () use ($receipt, $userId): void {
            $locked = $this->lockReceipt($receipt);
            abort_unless($locked->status === MaterialReceipt::STATUS_SUBMITTED, 403);
            $this->assertFits($locked);
            $locked->load('items.material', 'purchaseOrder');

            foreach ($locked->items as $line) {
                $accepted = Money::scale($line->quantity_accepted, 3) ?? '0.000';

                if (bccomp($accepted, '0', 3) !== 1 || $line->material_id === null) {
                    continue;
                }

                $material = Material::query()->whereKey($line->material_id)->lockForUpdate()->first();

                if (! $material || ! $material->is_active) {
                    throw ValidationException::withMessages([
                        'items' => 'Accepted stock must use an active inventory material.',
                    ]);
                }

                $this->assertUnit($material, (string) $line->unit);
                $this->stock->move(
                    (int) $locked->project_id,
                    (int) $material->id,
                    $locked->receipt_date->toDateString(),
                    StockLedgerEntry::RECEIPT,
                    'grn',
                    (int) $locked->id,
                    (int) $line->id,
                    $locked->receipt_number,
                    $accepted,
                    '0',
                    $userId,
                    $line->description,
                );
            }

            $locked->update([
                'status' => MaterialReceipt::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by' => $userId,
            ]);
        });
    }

    public function reject(MaterialReceipt $receipt, int $userId, string $reason): void
    {
        DB::transaction(function () use ($receipt, $userId, $reason): void {
            $locked = $this->lockReceipt($receipt);
            abort_unless($locked->status === MaterialReceipt::STATUS_SUBMITTED, 403);
            $locked->update([
                'status' => MaterialReceipt::STATUS_REJECTED,
                'rejected_at' => now(),
                'rejected_by' => $userId,
                'rejection_reason' => $reason,
            ]);
        });
    }

    public function cancel(MaterialReceipt $receipt, int $userId): void
    {
        DB::transaction(function () use ($receipt, $userId): void {
            $locked = $this->lockReceipt($receipt);
            abort_unless($locked->status === MaterialReceipt::STATUS_POSTED, 403);
            $entries = StockLedgerEntry::query()
                ->where('source_type', 'grn')
                ->where('source_id', $locked->id)
                ->orderBy('id')
                ->get();

            foreach ($entries as $entry) {
                $this->stock->move(
                    (int) $entry->project_id,
                    (int) $entry->material_id,
                    now()->toDateString(),
                    StockLedgerEntry::REVERSAL,
                    'reversal',
                    (int) $locked->id,
                    (int) $entry->id,
                    $locked->receipt_number,
                    '0',
                    (string) $entry->stock_in,
                    $userId,
                    'Reversal of '.$locked->receipt_number,
                );
            }

            $locked->update([
                'status' => MaterialReceipt::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
            ]);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function lines(PurchaseOrder $order, array $rows, ?int $exceptReceiptId): array
    {
        $lines = [];
        $seen = [];

        foreach (array_values($rows) as $index => $row) {
            $received = Money::scale($row['quantity_received'] ?? null, 3) ?? '0.000';

            if (bccomp($received, '0', 3) !== 1) {
                continue;
            }

            $item = $order->items->firstWhere('id', (int) ($row['purchase_order_item_id'] ?? 0));

            if (! $item instanceof PurchaseOrderItem || isset($seen[$item->id])) {
                throw ValidationException::withMessages([
                    "items.$index.purchase_order_item_id" => 'Receive only items that belong to this purchase order.',
                ]);
            }

            $seen[$item->id] = true;
            $accepted = Money::scale($row['quantity_accepted'] ?? null, 3) ?? '0.000';
            $rejected = Money::scale($row['quantity_rejected'] ?? null, 3) ?? '0.000';
            $this->assertQuantities($index, $received, $accepted, $rejected, $this->progress->remaining($item, $exceptReceiptId));
            $materialId = $this->materialId($index, $row['material_id'] ?? null, (string) $item->unit);

            $lines[] = [
                'purchase_order_item_id' => $item->id,
                'material_id' => $materialId,
                'description' => $item->description,
                'unit' => $item->unit,
                'quantity_received' => $received,
                'quantity_accepted' => $accepted,
                'quantity_rejected' => $rejected,
                'quantity_previously_received' => $this->progress->committed($item->id, $exceptReceiptId),
                'remarks' => $this->nullable($row['remarks'] ?? null),
            ];
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'items' => 'Enter a received quantity for at least one purchase order item.',
            ]);
        }

        return $lines;
    }

    private function assertFits(MaterialReceipt $receipt): void
    {
        $receipt->load('purchaseOrder.items', 'items');
        $this->lockOrderItems($receipt->purchaseOrder);

        foreach ($receipt->items as $index => $line) {
            $item = $receipt->purchaseOrder->items->firstWhere('id', $line->purchase_order_item_id);

            if (! $item instanceof PurchaseOrderItem) {
                throw ValidationException::withMessages([
                    'items' => 'A receipt line is no longer on the purchase order.',
                ]);
            }

            $this->assertQuantities(
                $index,
                Money::scale($line->quantity_received, 3) ?? '0.000',
                Money::scale($line->quantity_accepted, 3) ?? '0.000',
                Money::scale($line->quantity_rejected, 3) ?? '0.000',
                $this->progress->remaining($item, $receipt->id),
            );

            if ($line->material_id) {
                $material = Material::query()->find($line->material_id);
                $this->assertUnit($material, (string) $line->unit);
            }
        }
    }

    private function assertQuantities(int $index, string $received, string $accepted, string $rejected, string $remaining): void
    {
        if (bccomp($accepted, '0', 3) === -1 || bccomp($rejected, '0', 3) === -1) {
            throw ValidationException::withMessages([
                "items.$index.quantity_accepted" => 'Accepted and rejected quantities cannot be negative.',
            ]);
        }

        if (bccomp(Money::add($accepted, $rejected, 3), $received, 3) !== 0) {
            throw ValidationException::withMessages([
                "items.$index.quantity_accepted" => 'Accepted and rejected quantities must equal the quantity received.',
            ]);
        }

        if (bccomp($received, $remaining, 3) === 1) {
            throw ValidationException::withMessages([
                "items.$index.quantity_received" => 'Received quantity cannot exceed the remaining purchase order quantity.',
            ]);
        }
    }

    private function materialId(int $index, mixed $materialId, string $unit): ?int
    {
        if ($materialId === null || $materialId === '') {
            return null;
        }

        $material = Material::query()->find($materialId);

        if (! $material || ! $material->is_active) {
            throw ValidationException::withMessages([
                "items.$index.material_id" => 'Select an active inventory material.',
            ]);
        }

        $this->assertUnit($material, $unit, "items.$index.material_id");

        return (int) $material->id;
    }

    private function assertUnit(?Material $material, string $unit, string $key = 'items'): void
    {
        if (! $material || strcasecmp(trim($material->unit), trim($unit)) !== 0) {
            throw ValidationException::withMessages([
                $key => 'The inventory material unit must match the purchase order unit.',
            ]);
        }
    }

    private function guardOrder(Project $project, PurchaseOrder $order): void
    {
        abort_unless((int) $order->project_id === (int) $project->id, 404);
        abort_unless($order->status === 'approved', 403);
    }

    private function lockReceipt(MaterialReceipt $receipt): MaterialReceipt
    {
        $locked = MaterialReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
        abort_unless($locked->isGrn(), 404);

        return $locked;
    }

    private function lockOrderItems(?PurchaseOrder $order): void
    {
        if (! $order) {
            return;
        }

        PurchaseOrderItem::query()
            ->where('purchase_order_id', $order->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
