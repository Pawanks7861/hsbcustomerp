<?php

namespace App\Services;

use App\Models\MaterialReceipt;
use App\Models\MaterialReceiptItem;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseOrderItem;
use App\Support\DocumentStatus;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class BoqReceiptProgress
{
    /**
     * Accepted quantity from posted GRNs, keyed by BOQ item id.
     *
     * @param  array<int, int>  $boqItemIds
     * @return array<int, string>
     */
    public function acceptedFor(array $boqItemIds): array
    {
        return $this->sumByBoqItem($boqItemIds, function ($query): void {
            $query->where('receipts.document_kind', MaterialReceipt::KIND_GRN)
                ->where('receipts.status', MaterialReceipt::STATUS_POSTED);
        }, 'lines.quantity_accepted');
    }

    /**
     * Approved purchase-order quantity, keyed by BOQ item id.
     *
     * @param  array<int, int>  $boqItemIds
     * @return array<int, string>
     */
    public function orderedFor(array $boqItemIds): array
    {
        if ($boqItemIds === []) {
            return [];
        }

        $rows = PurchaseOrderItem::query()
            ->selectRaw('COALESCE(purchase_order_items.boq_item_id, purchase_orders.boq_item_id) as boq_item_id, SUM(purchase_order_items.quantity) as ordered')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.status', DocumentStatus::APPROVED)
            ->where(function ($query) use ($boqItemIds): void {
                $query->whereIn('purchase_order_items.boq_item_id', $boqItemIds)
                    ->orWhere(function ($inner) use ($boqItemIds): void {
                        $inner->whereNull('purchase_order_items.boq_item_id')
                            ->whereIn('purchase_orders.boq_item_id', $boqItemIds);
                    });
            })
            ->groupBy(DB::raw('COALESCE(purchase_order_items.boq_item_id, purchase_orders.boq_item_id)'))
            ->get();

        return $this->mapSums($rows, 'ordered');
    }

    /**
     * Billed invoice quantity, keyed by BOQ item id.
     *
     * @param  array<int, int>  $boqItemIds
     * @return array<int, string>
     */
    public function invoicedFor(array $boqItemIds): array
    {
        if ($boqItemIds === []) {
            return [];
        }

        $rows = PurchaseInvoiceItem::query()
            ->selectRaw('COALESCE(purchase_order_items.boq_item_id, purchase_orders.boq_item_id) as boq_item_id, SUM(purchase_invoice_items.quantity) as invoiced')
            ->join('purchase_order_items', 'purchase_order_items.id', '=', 'purchase_invoice_items.purchase_order_item_id')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where(function ($query) use ($boqItemIds): void {
                $query->whereIn('purchase_order_items.boq_item_id', $boqItemIds)
                    ->orWhere(function ($inner) use ($boqItemIds): void {
                        $inner->whereNull('purchase_order_items.boq_item_id')
                            ->whereIn('purchase_orders.boq_item_id', $boqItemIds);
                    });
            })
            ->groupBy(DB::raw('COALESCE(purchase_order_items.boq_item_id, purchase_orders.boq_item_id)'))
            ->get();

        return $this->mapSums($rows, 'invoiced');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function receiptsFor(int $boqItemId): array
    {
        return MaterialReceiptItem::query()
            ->with('receipt:id,receipt_number,receipt_date,status,document_kind,project_id')
            ->whereHas('purchaseOrderItem', function ($query) use ($boqItemId): void {
                $query->where('boq_item_id', $boqItemId)
                    ->orWhere(function ($inner) use ($boqItemId): void {
                        $inner->whereNull('boq_item_id')
                            ->whereHas('purchaseOrder', fn ($order) => $order->where('boq_item_id', $boqItemId));
                    });
            })
            ->whereHas('receipt', function ($query): void {
                $query->where('document_kind', MaterialReceipt::KIND_GRN)
                    ->where('status', MaterialReceipt::STATUS_POSTED);
            })
            ->get()
            ->map(fn (MaterialReceiptItem $line) => [
                'id' => $line->receipt?->id,
                'receipt_number' => $line->receipt?->receipt_number,
                'receipt_date' => $line->receipt?->receipt_date?->toDateString(),
                'accepted' => (string) $line->quantity_accepted,
                'rejected' => (string) $line->quantity_rejected,
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $boqItemIds
     * @param  callable(\Illuminate\Database\Query\Builder): void  $constraint
     * @return array<int, string>
     */
    private function sumByBoqItem(array $boqItemIds, callable $constraint, string $column): array
    {
        if ($boqItemIds === []) {
            return [];
        }

        $query = DB::table('material_receipt_items as lines')
            ->join('material_receipts as receipts', 'receipts.id', '=', 'lines.material_receipt_id')
            ->join('purchase_order_items as po_lines', 'po_lines.id', '=', 'lines.purchase_order_item_id')
            ->join('purchase_orders as orders', 'orders.id', '=', 'po_lines.purchase_order_id')
            ->where(function ($link) use ($boqItemIds): void {
                $link->whereIn('po_lines.boq_item_id', $boqItemIds)
                    ->orWhere(function ($inner) use ($boqItemIds): void {
                        $inner->whereNull('po_lines.boq_item_id')
                            ->whereIn('orders.boq_item_id', $boqItemIds);
                    });
            });

        $constraint($query);

        $rows = $query
            ->groupBy(DB::raw('COALESCE(po_lines.boq_item_id, orders.boq_item_id)'))
            ->selectRaw('COALESCE(po_lines.boq_item_id, orders.boq_item_id) as boq_item_id, SUM('.$column.') as total')
            ->get();

        return $this->mapSums($rows, 'total');
    }

    /**
     * @param  iterable<int, object>  $rows
     * @return array<int, string>
     */
    private function mapSums(iterable $rows, string $column): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row->boq_item_id] = Money::scale((string) $row->{$column}, 3) ?? '0.000';
        }

        return $totals;
    }
}
