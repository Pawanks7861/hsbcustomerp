<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderRateAmendment;
use App\Models\User;
use App\Support\DocumentStatus;
use App\Support\Money;
use App\Support\PurchaseLines;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderRateAmendmentService
{
    public function __construct(private ActivityLogger $activity) {}

    public function request(
        PurchaseOrder $purchaseOrder,
        PurchaseOrderItem $item,
        string $proposedRate,
        string $reason,
        User $user,
    ): PurchaseOrderRateAmendment {
        if ($purchaseOrder->status !== DocumentStatus::APPROVED) {
            throw ValidationException::withMessages([
                'purchase_order' => 'Rate amendments can only be requested on approved purchase orders.',
            ]);
        }

        if ((int) $item->purchase_order_id !== (int) $purchaseOrder->id) {
            throw ValidationException::withMessages([
                'purchase_order_item_id' => 'The line does not belong to this purchase order.',
            ]);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Enter a reason for the rate amendment.',
            ]);
        }

        $proposedRate = Money::scale($proposedRate, 2);
        if ($proposedRate === null) {
            throw ValidationException::withMessages([
                'proposed_rate' => 'Enter a valid proposed rate.',
            ]);
        }

        $previousRate = Money::scale($item->rate, 2) ?? '0.00';
        $previousAmount = Money::scale($item->amount, 2) ?? '0.00';
        $proposedAmount = Money::itemAmount((string) $item->quantity, $proposedRate) ?? '0.00';

        if (bccomp($previousRate, $proposedRate, 2) === 0) {
            throw ValidationException::withMessages([
                'proposed_rate' => 'Proposed rate must differ from the current rate.',
            ]);
        }

        if (PurchaseOrderRateAmendment::query()
            ->where('purchase_order_item_id', $item->id)
            ->where('status', PurchaseOrderRateAmendment::STATUS_PENDING)
            ->exists()) {
            throw ValidationException::withMessages([
                'purchase_order_item_id' => 'This line already has a pending rate amendment.',
            ]);
        }

        return PurchaseOrderRateAmendment::query()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'purchase_order_item_id' => $item->id,
            'previous_rate' => $previousRate,
            'proposed_rate' => $proposedRate,
            'previous_amount' => $previousAmount,
            'proposed_amount' => $proposedAmount,
            'reason' => $reason,
            'status' => PurchaseOrderRateAmendment::STATUS_PENDING,
            'requested_by' => $user->id,
            'requested_at' => now(),
        ]);
    }

    public function approve(
        PurchaseOrderRateAmendment $amendment,
        User $user,
        ?string $decisionComment = null,
    ): PurchaseOrderRateAmendment {
        if (! $amendment->isPending()) {
            throw ValidationException::withMessages([
                'amendment' => 'This rate amendment is no longer pending.',
            ]);
        }

        return DB::transaction(function () use ($amendment, $user, $decisionComment): PurchaseOrderRateAmendment {
            $amendment = PurchaseOrderRateAmendment::query()->lockForUpdate()->findOrFail($amendment->id);
            if (! $amendment->isPending()) {
                throw ValidationException::withMessages([
                    'amendment' => 'This rate amendment is no longer pending.',
                ]);
            }

            $purchaseOrder = PurchaseOrder::query()->lockForUpdate()->with('items')->findOrFail($amendment->purchase_order_id);
            if ($purchaseOrder->status !== DocumentStatus::APPROVED) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Only approved purchase orders can have rate amendments applied.',
                ]);
            }

            $item = $purchaseOrder->items->firstWhere('id', $amendment->purchase_order_item_id);
            if ($item === null) {
                throw ValidationException::withMessages([
                    'purchase_order_item_id' => 'The purchase order line could not be found.',
                ]);
            }

            $commercial = $this->commercialFromOrder($purchaseOrder);
            $gstPercentage = (string) $purchaseOrder->gst_percentage;
            $source = $this->pricedSource($purchaseOrder);
            $index = $purchaseOrder->items->search(fn (PurchaseOrderItem $line) => (int) $line->id === (int) $item->id);

            if ($index === false) {
                throw ValidationException::withMessages([
                    'purchase_order_item_id' => 'The purchase order line could not be found.',
                ]);
            }

            $source[$index]['rate'] = Money::scale($amendment->proposed_rate, 2) ?? (string) $amendment->proposed_rate;
            $source[$index]['amount'] = Money::itemAmount((string) $item->quantity, $source[$index]['rate']) ?? (string) $amendment->proposed_amount;

            $priced = PurchaseLines::price($source, $gstPercentage, $commercial);
            $line = $priced[$index];

            $item->update([
                'rate' => Money::scale($line['rate'] ?? $item->rate, 2) ?? (string) $item->rate,
                'amount' => Money::scale($line['amount'] ?? $item->amount, 2) ?? (string) $item->amount,
                'discount_percentage' => $line['discount_percentage'],
                'discount_amount' => $line['discount_amount'],
                'taxable_amount' => $line['taxable_amount'],
                'cgst_amount' => $line['cgst_amount'],
                'sgst_amount' => $line['sgst_amount'],
                'line_total' => $line['line_total'],
            ]);

            $purchaseOrder->update(PurchaseLines::summarize($priced, $gstPercentage, $commercial));

            $amendment->update([
                'status' => PurchaseOrderRateAmendment::STATUS_APPROVED,
                'approved_by' => $user->id,
                'decided_at' => now(),
                'decision_comment' => $this->nullableComment($decisionComment),
            ]);

            $this->activity->log($user->id, 'purchase_order.rate_amendment_approved', $purchaseOrder, [
                'po_number' => $purchaseOrder->po_number,
                'amendment_id' => $amendment->id,
                'item_id' => $item->id,
                'description' => $item->description,
                'previous_rate' => (string) $amendment->previous_rate,
                'proposed_rate' => (string) $amendment->proposed_rate,
                'reason' => $amendment->reason,
                'decision_comment' => $amendment->decision_comment,
            ]);

            return $amendment->fresh(['purchaseOrderItem', 'requestedBy', 'approvedBy']);
        });
    }

    public function reject(
        PurchaseOrderRateAmendment $amendment,
        User $user,
        ?string $decisionComment = null,
    ): PurchaseOrderRateAmendment {
        if (! $amendment->isPending()) {
            throw ValidationException::withMessages([
                'amendment' => 'This rate amendment is no longer pending.',
            ]);
        }

        return DB::transaction(function () use ($amendment, $user, $decisionComment): PurchaseOrderRateAmendment {
            $amendment = PurchaseOrderRateAmendment::query()->lockForUpdate()->findOrFail($amendment->id);
            if (! $amendment->isPending()) {
                throw ValidationException::withMessages([
                    'amendment' => 'This rate amendment is no longer pending.',
                ]);
            }

            $purchaseOrder = PurchaseOrder::query()->findOrFail($amendment->purchase_order_id);

            $amendment->update([
                'status' => PurchaseOrderRateAmendment::STATUS_REJECTED,
                'rejected_by' => $user->id,
                'decided_at' => now(),
                'decision_comment' => $this->nullableComment($decisionComment),
            ]);

            $this->activity->log($user->id, 'purchase_order.rate_amendment_rejected', $purchaseOrder, [
                'po_number' => $purchaseOrder->po_number,
                'amendment_id' => $amendment->id,
                'item_id' => $amendment->purchase_order_item_id,
                'previous_rate' => (string) $amendment->previous_rate,
                'proposed_rate' => (string) $amendment->proposed_rate,
                'reason' => $amendment->reason,
                'decision_comment' => $amendment->decision_comment,
            ]);

            return $amendment->fresh(['purchaseOrderItem', 'requestedBy', 'rejectedBy']);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rateHistory(PurchaseOrder $purchaseOrder): array
    {
        $amendments = PurchaseOrderRateAmendment::query()
            ->with(['purchaseOrderItem:id,description', 'requestedBy:id,name', 'approvedBy:id,name', 'rejectedBy:id,name'])
            ->where('purchase_order_id', $purchaseOrder->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (PurchaseOrderRateAmendment $amendment) => [
                'id' => $amendment->id,
                'type' => 'amendment',
                'status' => $amendment->status,
                'item_description' => $amendment->purchaseOrderItem?->description,
                'previous_rate' => (string) $amendment->previous_rate,
                'proposed_rate' => (string) $amendment->proposed_rate,
                'reason' => $amendment->reason,
                'requested_by' => $amendment->requestedBy?->name,
                'requested_at' => $amendment->requested_at?->toDateTimeString(),
                'decided_at' => $amendment->decided_at?->toDateTimeString(),
                'decided_by' => $amendment->approvedBy?->name ?? $amendment->rejectedBy?->name,
                'decision_comment' => $amendment->decision_comment,
            ])
            ->all();

        $logs = ActivityLog::query()
            ->with('user:id,name')
            ->where('subject_type', PurchaseOrder::class)
            ->where('subject_id', $purchaseOrder->id)
            ->whereIn('action', [
                'purchase_order.rate_changed',
                'purchase_order.rate_amendment_approved',
                'purchase_order.rate_amendment_rejected',
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'type' => 'activity',
                'action' => $log->action,
                'user' => $log->user?->name,
                'properties' => $log->properties,
                'created_at' => $log->created_at?->toDateTimeString(),
            ])
            ->all();

        return [
            'amendments' => $amendments,
            'activity' => $logs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commercialFromOrder(PurchaseOrder $purchaseOrder): array
    {
        $commercial = [
            'freight' => (string) $purchaseOrder->freight,
            'round_off' => (string) $purchaseOrder->round_off,
        ];

        if ((float) $purchaseOrder->cgst_percentage > 0 || (float) $purchaseOrder->sgst_percentage > 0) {
            $commercial['cgst_percentage'] = (string) $purchaseOrder->cgst_percentage;
            $commercial['sgst_percentage'] = (string) $purchaseOrder->sgst_percentage;
        }

        return $commercial;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pricedSource(PurchaseOrder $purchaseOrder): array
    {
        return $purchaseOrder->items->map(fn (PurchaseOrderItem $item) => [
            'quantity' => (string) $item->quantity,
            'rate' => (string) $item->rate,
            'amount' => (string) $item->amount,
            'hsn_sac' => $item->hsn_sac,
            'discount_percentage' => (string) $item->discount_percentage,
        ])->all();
    }

    private function nullableComment(?string $comment): ?string
    {
        $comment = trim((string) $comment);

        return $comment === '' ? null : $comment;
    }
}
