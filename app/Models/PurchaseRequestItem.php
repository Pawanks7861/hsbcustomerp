<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequestItem extends Model
{
    protected $fillable = [
        'purchase_request_id',
        'boq_item_id',
        'description',
        'service_code',
        'unit',
        'quantity',
        'estimated_rate',
        'estimated_amount',
        'remarks',
        'is_boq_quantity_exceeded',
        'boq_quantity_at_submission',
        'previous_requested_quantity',
        'excess_quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'estimated_rate' => 'decimal:2',
        'estimated_amount' => 'decimal:2',
        'is_boq_quantity_exceeded' => 'boolean',
        'boq_quantity_at_submission' => 'decimal:3',
        'previous_requested_quantity' => 'decimal:3',
        'excess_quantity' => 'decimal:3',
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }
}
