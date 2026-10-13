<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceItem extends Model
{
    protected $fillable = [
        'purchase_invoice_id',
        'purchase_order_item_id',
        'service_code',
        'description',
        'unit',
        'po_quantity',
        'previously_invoiced',
        'quantity',
        'rate',
        'discount_percentage',
        'amount',
        'taxable_amount',
        'cgst_amount',
        'sgst_amount',
        'line_total',
    ];

    protected $casts = [
        'po_quantity' => 'decimal:3',
        'previously_invoiced' => 'decimal:3',
        'quantity' => 'decimal:3',
        'rate' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'amount' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }
}
