<?php

namespace App\Services;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Support\DocumentStatus;
use App\Support\Money;
use App\Support\PurchaseLines;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseInvoiceLineService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function linesFor(PurchaseOrder $order, ?int $exceptInvoiceId = null): array
    {
        $order->loadMissing('items');
        $invoiced = $this->invoicedQuantities($order->items->pluck('id')->all(), $exceptInvoiceId);

        return $order->items->map(function (PurchaseOrderItem $item) use ($invoiced, $order): array {
            $ordered = Money::scale($item->quantity, 3) ?? '0.000';
            $previous = $invoiced[$item->id] ?? '0.000';
            $remaining = Money::sub($ordered, $previous, 3);

            if (bccomp($remaining, '0', 3) === -1) {
                $remaining = '0.000';
            }

            return [
                'id' => $item->id,
                'service_code' => $item->service_code,
                'description' => $item->description,
                'unit' => $item->unit,
                'po_quantity' => $ordered,
                'previously_invoiced' => $previous,
                'remaining' => $remaining,
                'rate' => (string) $item->rate,
                'discount_percentage' => (string) ($item->discount_percentage ?? '0.00'),
                'cgst_percentage' => (string) ($order->cgst_percentage ?? '0.00'),
                'sgst_percentage' => (string) ($order->sgst_percentage ?? '0.00'),
                'gst_percentage' => (string) ($order->gst_percentage ?? '0.00'),
            ];
        })->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{invoice_amount: string, tax_percentage: string, tax_amount: string, round_off: string, total_amount: string}
     */
    public function sync(
        PurchaseInvoice $invoice,
        PurchaseOrder $order,
        array $rows,
        ?string $taxPercentage = null,
        string $roundOffMode = 'auto',
        mixed $manualRoundOff = null,
    ): array {
        if ($order->status !== DocumentStatus::APPROVED) {
            throw ValidationException::withMessages([
                'purchase_order_id' => 'Select an approved purchase order.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $order, $rows, $taxPercentage, $roundOffMode, $manualRoundOff): array {
            $requested = $this->requestedQuantities($rows);
            $ids = array_keys($requested);
            sort($ids);

            $locked = PurchaseOrderItem::query()
                ->where('purchase_order_id', $order->id)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($locked->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'items' => 'Each invoice line must belong to the selected purchase order.',
                ]);
            }

            $already = [];

            foreach (PurchaseInvoiceItem::query()
                ->whereIn('purchase_order_item_id', $ids)
                ->where('purchase_invoice_id', '!=', $invoice->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get() as $existing) {
                $key = (int) $existing->purchase_order_item_id;
                $already[$key] = Money::add($already[$key] ?? '0.000', (string) $existing->quantity, 3);
            }
            $pricedRows = [];
            $snapshots = [];

            foreach ($ids as $id) {
                $item = $locked[$id];
                $ordered = Money::scale($item->quantity, 3) ?? '0.000';
                $previous = $already[$id] ?? '0.000';
                $quantity = $requested[$id]['quantity'];
                $index = $requested[$id]['index'];
                $remaining = Money::sub($ordered, $previous, 3);

                if (bccomp($quantity, $remaining, 3) === 1) {
                    throw ValidationException::withMessages([
                        "items.$index.quantity" => 'Invoice quantity cannot exceed the remaining billable quantity of '.$remaining.'.',
                    ]);
                }

                $rate = Money::scale($item->rate, 2) ?? '0.00';
                $pricedRows[] = [
                    'amount' => Money::itemAmount($quantity, $rate) ?? '0.00',
                    'discount_percentage' => $item->discount_percentage ?? 0,
                    'hsn_sac' => $item->hsn_sac,
                ];
                $snapshots[] = [
                    'item' => $item,
                    'quantity' => $quantity,
                    'previous' => $previous,
                    'ordered' => $ordered,
                    'rate' => $rate,
                ];
            }

            if ($pricedRows === []) {
                throw ValidationException::withMessages([
                    'items' => 'Enter a quantity for at least one purchase order item.',
                ]);
            }

            $commercial = $taxPercentage === null ? $this->commercial($order) : [];
            $rate = $taxPercentage ?? $order->gst_percentage;
            $priced = PurchaseLines::price($pricedRows, $rate, $commercial);
            $preRound = PurchaseLines::summarize($priced, $rate, $commercial);
            $beforeRound = Money::add($preRound['taxable_value'], $preRound['gst_amount']);
            $commercial['round_off'] = $this->resolveRoundOff($beforeRound, $roundOffMode, $manualRoundOff);
            $totals = PurchaseLines::summarize($priced, $rate, $commercial);

            $invoice->items()->delete();

            foreach ($snapshots as $index => $snapshot) {
                $pricedLine = $priced[$index];
                $item = $snapshot['item'];

                PurchaseInvoiceItem::query()->create([
                    'purchase_invoice_id' => $invoice->id,
                    'purchase_order_item_id' => $item->id,
                    'service_code' => $item->service_code,
                    'description' => $item->description,
                    'unit' => $item->unit,
                    'po_quantity' => $snapshot['ordered'],
                    'previously_invoiced' => $snapshot['previous'],
                    'quantity' => $snapshot['quantity'],
                    'rate' => $snapshot['rate'],
                    'discount_percentage' => $pricedLine['discount_percentage'],
                    'amount' => $pricedLine['amount'],
                    'taxable_amount' => $pricedLine['taxable_amount'],
                    'cgst_amount' => $pricedLine['cgst_amount'],
                    'sgst_amount' => $pricedLine['sgst_amount'],
                    'line_total' => $pricedLine['line_total'],
                ]);
            }

            return [
                'invoice_amount' => $totals['taxable_value'],
                'tax_percentage' => $totals['gst_percentage'],
                'tax_amount' => $totals['gst_amount'],
                'round_off' => $totals['round_off'],
                'total_amount' => $totals['grand_total'],
            ];
        });
    }

    /**
     * @param  array<int, int>  $poItemIds
     * @return array<int, string>
     */
    public function invoicedQuantities(array $poItemIds, ?int $exceptInvoiceId = null): array
    {
        if ($poItemIds === []) {
            return [];
        }

        $rows = PurchaseInvoiceItem::query()
            ->selectRaw('purchase_order_item_id, SUM(quantity) as invoiced')
            ->whereIn('purchase_order_item_id', $poItemIds)
            ->when($exceptInvoiceId, fn ($query) => $query->where('purchase_invoice_id', '!=', $exceptInvoiceId))
            ->groupBy('purchase_order_item_id')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row->purchase_order_item_id] = Money::scale((string) $row->invoiced, 3) ?? '0.000';
        }

        return $totals;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function requestedQuantities(array $rows): array
    {
        $requested = [];

        foreach (array_values($rows) as $index => $row) {
            $id = (int) ($row['purchase_order_item_id'] ?? 0);
            $raw = $row['quantity'] ?? null;

            if ($raw === null || $raw === '' || ! is_numeric($raw)) {
                continue;
            }

            $quantity = Money::scale($raw, 3);

            if ($id === 0 || $quantity === null || bccomp($quantity, '0', 3) !== 1) {
                continue;
            }

            if (isset($requested[$id])) {
                throw ValidationException::withMessages([
                    "items.$index.purchase_order_item_id" => 'This purchase order item is already on the invoice.',
                ]);
            }

            $requested[$id] = ['quantity' => $quantity, 'index' => $index];
        }

        if ($requested === []) {
            throw ValidationException::withMessages([
                'items' => 'Enter a quantity for at least one purchase order item.',
            ]);
        }

        return $requested;
    }

    /**
     * @return array<string, mixed>
     */
    private function commercial(PurchaseOrder $order): array
    {
        if ($order->cgst_percentage === null && $order->sgst_percentage === null) {
            return [];
        }

        return [
            'cgst_percentage' => $order->cgst_percentage ?? 0,
            'sgst_percentage' => $order->sgst_percentage ?? 0,
        ];
    }

    private function resolveRoundOff(string $beforeRound, string $mode, mixed $manual): string
    {
        if ($mode === 'manual') {
            $value = Money::scale($manual ?? 0, 2) ?? '0.00';

            if (bccomp(ltrim($value, '-'), '100.00', 2) === 1) {
                throw ValidationException::withMessages([
                    'round_off' => 'Round off cannot exceed ₹100.00.',
                ]);
            }

            return $value;
        }

        $nearest = Money::scale(round((float) $beforeRound), 2) ?? $beforeRound;

        return Money::sub($nearest, $beforeRound);
    }
}
