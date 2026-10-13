<?php

namespace App\Services;

use App\Models\MaterialReceipt;
use App\Models\MaterialReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Support\Money;

class PoReceiptProgress
{
    public function committed(int $poItemId, ?int $exceptReceiptId = null): string
    {
        $legacy = $this->sum($poItemId, 'quantity_received', function ($query): void {
            $query->where(function ($inner): void {
                $inner->where('document_kind', MaterialReceipt::KIND_STORE)
                    ->orWhere('status', MaterialReceipt::STATUS_LEGACY);
            });
        });

        $posted = $this->sum($poItemId, 'quantity_accepted', function ($query): void {
            $query->where('document_kind', MaterialReceipt::KIND_GRN)
                ->where('status', MaterialReceipt::STATUS_POSTED);
        });

        $pending = $this->sum($poItemId, 'quantity_received', function ($query) use ($exceptReceiptId): void {
            $query->where('document_kind', MaterialReceipt::KIND_GRN)
                ->where('status', MaterialReceipt::STATUS_SUBMITTED);

            if ($exceptReceiptId) {
                $query->whereKeyNot($exceptReceiptId);
            }
        });

        return Money::add(Money::add($legacy, $posted, 3), $pending, 3);
    }

    public function fulfilled(int $poItemId): string
    {
        $legacy = $this->sum($poItemId, 'quantity_received', function ($query): void {
            $query->where(function ($inner): void {
                $inner->where('document_kind', MaterialReceipt::KIND_STORE)
                    ->orWhere('status', MaterialReceipt::STATUS_LEGACY);
            });
        });

        $posted = $this->sum($poItemId, 'quantity_accepted', function ($query): void {
            $query->where('document_kind', MaterialReceipt::KIND_GRN)
                ->where('status', MaterialReceipt::STATUS_POSTED);
        });

        return Money::add($legacy, $posted, 3);
    }

    public function remaining(PurchaseOrderItem $item, ?int $exceptReceiptId = null): string
    {
        $ordered = Money::scale($item->quantity, 3) ?? '0.000';
        $left = Money::sub($ordered, $this->committed($item->id, $exceptReceiptId), 3);

        return bccomp($left, '0', 3) === -1 ? '0.000' : $left;
    }

    public function label(PurchaseOrder $order): string
    {
        $order->loadMissing('items');

        if ($order->items->isEmpty()) {
            return 'Not Received';
        }

        $any = false;
        $complete = true;

        foreach ($order->items as $item) {
            $ordered = Money::scale($item->quantity, 3) ?? '0.000';
            $got = $this->fulfilled($item->id);

            if (bccomp($got, '0', 3) === 1) {
                $any = true;
            }

            if (bccomp($got, $ordered, 3) === -1) {
                $complete = false;
            }
        }

        if (! $any) {
            return 'Not Received';
        }

        return $complete ? 'Fully Received' : 'Partially Received';
    }

    /**
     * @param  callable(\Illuminate\Database\Eloquent\Builder<MaterialReceipt>): void  $constraint
     */
    private function sum(int $poItemId, string $column, callable $constraint): string
    {
        $total = MaterialReceiptItem::query()
            ->where('purchase_order_item_id', $poItemId)
            ->whereHas('receipt', $constraint)
            ->sum($column);

        return Money::scale((string) $total, 3) ?? '0.000';
    }
}
