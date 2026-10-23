<?php

namespace App\Services;

use App\Models\DebitNoteItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Support\DocumentStatus;
use App\Support\Money;
use App\Support\PurchaseLines;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PurchaseRequestPoSyncService
{
    public function __construct(
        private PoReceiptProgress $receipts,
        private PurchaseInvoiceLineService $invoices,
        private ActivityLogger $logger,
    ) {}

    /**
     * @param  Collection<int, PurchaseRequestItem>|null  $removing
     */
    public function synchronize(PurchaseRequest $purchaseRequest, ?Collection $removing = null): void
    {
        $removing ??= collect();
        $purchaseRequest->loadMissing('items');

        $orders = PurchaseOrder::query()
            ->where('purchase_request_id', $purchaseRequest->id)
            ->where('status', '!=', DocumentStatus::REJECTED)
            ->lockForUpdate()
            ->with('items')
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            return;
        }

        foreach ($orders as $order) {
            $this->syncOrder($purchaseRequest, $order, $removing);
        }
    }

    /**
     * @param  Collection<int, PurchaseRequestItem>  $removing
     */
    private function syncOrder(PurchaseRequest $purchaseRequest, PurchaseOrder $order, Collection $removing): void
    {
        $this->linkLegacyLines($purchaseRequest, $order);
        $order->load('items');

        $byPrLine = $order->items
            ->filter(fn (PurchaseOrderItem $item) => $item->purchase_request_item_id)
            ->keyBy(fn (PurchaseOrderItem $item) => (int) $item->purchase_request_item_id);

        $changed = false;
        $keptPoIds = [];

        foreach ($purchaseRequest->items as $prLine) {
            $poLine = $byPrLine->get((int) $prLine->id);

            if ($poLine) {
                $changed = $this->updateExistingLine($order, $poLine, $prLine) || $changed;
                $keptPoIds[] = (int) $poLine->id;
                continue;
            }

            $created = $this->createLine($order, $prLine);
            $keptPoIds[] = (int) $created->id;
            $changed = true;
        }

        foreach ($removing as $removed) {
            $poLine = $byPrLine->get((int) $removed->id);

            if (! $poLine) {
                continue;
            }

            $this->assertLineRemovable($order, $poLine, $removed);
            $poLine->delete();
            $changed = true;
        }

        foreach ($order->items as $orphan) {
            if (in_array((int) $orphan->id, $keptPoIds, true)) {
                continue;
            }

            if ($orphan->purchase_request_item_id && $purchaseRequest->items->contains('id', $orphan->purchase_request_item_id)) {
                continue;
            }

            if ($orphan->purchase_request_item_id && $removing->contains('id', $orphan->purchase_request_item_id)) {
                continue;
            }
        }

        if (! $changed) {
            return;
        }

        $this->reprice($order);

        if (in_array($order->status, [DocumentStatus::SUBMITTED, DocumentStatus::APPROVED], true)) {
            $order->update([
                'status' => DocumentStatus::SUBMITTED,
                'submitted_at' => $order->submitted_at ?? now(),
                'approved_at' => null,
                'approved_by' => null,
                'first_approved_by' => null,
                'first_approved_at' => null,
                'second_approved_by' => null,
                'second_approved_at' => null,
            ]);
        }

        $this->logger->log(auth()->id(), 'purchase_order.synced_from_pr', $order, [
            'purchase_request_id' => $purchaseRequest->id,
            'pr_number' => $purchaseRequest->pr_number,
            'po_number' => $order->po_number,
        ]);
    }

    private function updateExistingLine(PurchaseOrder $order, PurchaseOrderItem $poLine, PurchaseRequestItem $prLine): bool
    {
        $quantity = Money::scale($prLine->quantity, 3) ?? '0.000';
        $this->assertQuantitySafe($order, $poLine, $quantity, $prLine);

        $dirty = $poLine->name !== $prLine->name
            || $poLine->description !== $prLine->description
            || (string) $poLine->unit !== (string) $prLine->unit
            || (int) ($poLine->boq_item_id ?? 0) !== (int) ($prLine->boq_item_id ?? 0)
            || bccomp((string) $poLine->quantity, $quantity, 3) !== 0;

        if (! $dirty) {
            return false;
        }

        $rate = Money::scale($poLine->rate, 2) ?? '0.00';
        $poLine->update([
            'name' => $prLine->name,
            'description' => $prLine->description,
            'unit' => $prLine->unit,
            'quantity' => $quantity,
            'boq_item_id' => $prLine->boq_item_id,
            'service_code' => $prLine->service_code,
            'amount' => Money::itemAmount($quantity, $rate) ?? '0.00',
        ]);

        return true;
    }

    private function createLine(PurchaseOrder $order, PurchaseRequestItem $prLine): PurchaseOrderItem
    {
        $existing = $order->items()
            ->where('purchase_request_item_id', $prLine->id)
            ->first();

        if ($existing) {
            $this->updateExistingLine($order, $existing, $prLine);

            return $existing->fresh();
        }

        $quantity = Money::scale($prLine->quantity, 3) ?? '0.000';
        $rate = Money::scale($prLine->estimated_rate, 2) ?? '0.00';
        $priced = PurchaseLines::price([[
            'name' => $prLine->name,
            'description' => $prLine->description,
            'unit' => $prLine->unit,
            'quantity' => $quantity,
            'rate' => $rate,
            'amount' => Money::itemAmount($quantity, $rate) ?? '0.00',
            'service_code' => $prLine->service_code,
            'boq_item_id' => $prLine->boq_item_id,
            'purchase_request_item_id' => $prLine->id,
            'discount_percentage' => '0',
            'remarks' => $prLine->remarks,
        ]], $order->gst_percentage, [
            'cgst_percentage' => $order->cgst_percentage,
            'sgst_percentage' => $order->sgst_percentage,
        ])[0];

        return $order->items()->create($priced);
    }

    private function linkLegacyLines(PurchaseRequest $purchaseRequest, PurchaseOrder $order): void
    {
        $unlinked = $order->items
            ->filter(fn (PurchaseOrderItem $item) => $item->purchase_request_item_id === null)
            ->values();

        if ($unlinked->isEmpty()) {
            return;
        }

        $claimed = $order->items
            ->pluck('purchase_request_item_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $available = $purchaseRequest->items
            ->reject(fn (PurchaseRequestItem $item) => in_array((int) $item->id, $claimed, true))
            ->values();

        foreach ($unlinked as $index => $poLine) {
            $prLine = $available->get($index);

            if (! $prLine) {
                break;
            }

            $poLine->update(['purchase_request_item_id' => $prLine->id]);
        }
    }

    private function assertQuantitySafe(PurchaseOrder $order, PurchaseOrderItem $poLine, string $quantity, PurchaseRequestItem $prLine): void
    {
        $protected = $this->protectedQuantity($poLine);

        if (bccomp($quantity, $protected, 3) === -1) {
            throw ValidationException::withMessages([
                $this->lineKey($prLine) => 'Cannot reduce this item below the quantity already received or invoiced on purchase order '.$order->po_number.'.',
            ]);
        }
    }

    private function assertLineRemovable(PurchaseOrder $order, PurchaseOrderItem $poLine, PurchaseRequestItem $prLine): void
    {
        if (bccomp($this->protectedQuantity($poLine), '0', 3) === 1) {
            throw ValidationException::withMessages([
                $this->lineKey($prLine) => 'This item cannot be removed because purchase order '.$order->po_number.' already has receipts or invoices against it.',
            ]);
        }

        if (DebitNoteItem::query()->where('purchase_order_item_id', $poLine->id)->exists()) {
            throw ValidationException::withMessages([
                $this->lineKey($prLine) => 'This item cannot be removed because a debit note exists on purchase order '.$order->po_number.'.',
            ]);
        }
    }

    private function protectedQuantity(PurchaseOrderItem $poLine): string
    {
        $received = $this->receipts->committed($poLine->id);
        $invoiced = $this->invoices->invoicedQuantities([$poLine->id])[$poLine->id] ?? '0.000';

        return bccomp($received, $invoiced, 3) === 1 ? $received : $invoiced;
    }

    private function reprice(PurchaseOrder $order): void
    {
        $order->load('items');
        $commercial = [
            'freight' => $order->freight,
            'round_off' => $order->round_off,
            'cgst_percentage' => $order->cgst_percentage,
            'sgst_percentage' => $order->sgst_percentage,
        ];

        $priced = PurchaseLines::price(
            $order->items->map(fn (PurchaseOrderItem $item) => [
                'id' => $item->id,
                'quantity' => (string) $item->quantity,
                'rate' => (string) $item->rate,
                'amount' => Money::itemAmount($item->quantity, $item->rate) ?? '0.00',
                'hsn_sac' => $item->hsn_sac,
                'discount_percentage' => (string) $item->discount_percentage,
            ])->all(),
            $order->gst_percentage,
            $commercial
        );

        foreach ($priced as $row) {
            PurchaseOrderItem::query()->whereKey($row['id'])->update([
                'amount' => $row['amount'],
                'discount_amount' => $row['discount_amount'],
                'taxable_amount' => $row['taxable_amount'],
                'cgst_amount' => $row['cgst_amount'],
                'sgst_amount' => $row['sgst_amount'],
                'line_total' => $row['line_total'],
            ]);
        }

        $order->update(PurchaseLines::summarize($priced, $order->gst_percentage, $commercial));
    }

    private function lineKey(PurchaseRequestItem $prLine): string
    {
        $index = $prLine->purchaseRequest?->items
            ->values()
            ->search(fn (PurchaseRequestItem $item) => (int) $item->id === (int) $prLine->id);

        return is_int($index) ? "items.$index.quantity" : 'items';
    }
}
