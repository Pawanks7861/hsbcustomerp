<?php

namespace App\Http\Controllers;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseOrderItem;
use App\Services\Access;
use App\Services\BoqProcurementQuantityService;
use App\Services\BoqReceiptProgress;
use App\Services\WorkOrderService;
use App\Support\BoqTotals;
use Inertia\Inertia;
use Inertia\Response;

class BoqItemProcurementController extends Controller
{
    public function show(Project $project, Boq $boq, BoqItem $item, BoqProcurementQuantityService $quantities, BoqReceiptProgress $receipts, WorkOrderService $workOrders, Access $access): Response
    {
        abort_unless($boq->project_id === $project->id, 404);
        abort_unless($item->boq_id === $boq->id, 404);

        $requests = $item->purchaseRequests()
            ->with(['requestedBy', 'items'])
            ->orderByDesc('id')
            ->get();

        $lineOrderIds = PurchaseOrderItem::query()
            ->where('boq_item_id', $item->id)
            ->pluck('purchase_order_id');

        $orders = PurchaseOrder::query()
            ->with('purchaseRequest')
            ->where(function ($query) use ($item, $lineOrderIds): void {
                $query->where('boq_item_id', $item->id)
                    ->orWhereIn('id', $lineOrderIds);
            })
            ->orderByDesc('id')
            ->get();

        $progress = [
            'ordered_quantity' => $receipts->orderedFor([$item->id])[$item->id] ?? '0.000',
            'accepted_quantity' => $receipts->acceptedFor([$item->id])[$item->id] ?? '0.000',
            'received_quantity' => $receipts->recordedFor([$item->id])[$item->id] ?? '0.000',
            'invoiced_quantity' => $receipts->invoicedFor([$item->id])[$item->id] ?? '0.000',
        ];

        return Inertia::render('Boqs/Procurement', [
            'project' => [
                'id' => $project->id,
                'project_name' => $project->project_name,
                'project_code' => $project->project_code,
            ],
            'boq' => [
                'id' => $boq->id,
                'title' => $boq->title,
            ],
            'item' => BoqTotals::item($item),
            'quantitySummary' => $quantities->getSummary($item),
            'receiptSummary' => $progress,
            'goodsReceipts' => $receipts->receiptsFor($item->id),
            'workOrderSummary' => $workOrders->summaryForItem($item),
            'workOrders' => $workOrders->historyForItem($item),
            'canCreateWorkOrder' => $access->allows(auth()->user(), 'work_orders.create'),
            'purchaseRequests' => $requests->map(fn (PurchaseRequest $record) => [
                'id' => $record->id,
                'pr_number' => $record->pr_number,
                'request_date' => $record->request_date?->toDateString(),
                'status' => $record->status,
                'requested_by' => $record->requestedBy?->name,
                'estimated_total' => $record->estimatedTotal(),
            ])->values(),
            'purchaseOrders' => $orders->map(fn (PurchaseOrder $record) => [
                'id' => $record->id,
                'po_number' => $record->po_number,
                'pr_number' => $record->purchaseRequest?->pr_number,
                'supplier_name' => $record->supplier_name,
                'po_date' => $record->po_date?->toDateString(),
                'status' => $record->status,
                'grand_total' => (string) $record->grand_total,
            ])->values(),
        ]);
    }
}
