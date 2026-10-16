<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderRateAmendment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'purchase_order_id',
        'purchase_order_item_id',
        'previous_rate',
        'proposed_rate',
        'previous_amount',
        'proposed_amount',
        'reason',
        'status',
        'requested_by',
        'approved_by',
        'rejected_by',
        'requested_at',
        'decided_at',
        'decision_comment',
    ];

    protected $casts = [
        'previous_rate' => 'decimal:2',
        'proposed_rate' => 'decimal:2',
        'previous_amount' => 'decimal:2',
        'proposed_amount' => 'decimal:2',
        'requested_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
