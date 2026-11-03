<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'purchase_request_item_id',
        'boq_item_id',
        'name',
        'description',
        'hsn_sac',
        'service_code',
        'unit',
        'quantity',
        'rate',
        'amount',
        'discount_percentage',
        'discount_amount',
        'taxable_amount',
        'gst_percentage',
        'cgst_percentage',
        'sgst_percentage',
        'igst_percentage',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'line_total',
        'remarks',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'gst_percentage' => 'decimal:2',
        'cgst_percentage' => 'decimal:2',
        'sgst_percentage' => 'decimal:2',
        'igst_percentage' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseRequestItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestItem::class);
    }
}
