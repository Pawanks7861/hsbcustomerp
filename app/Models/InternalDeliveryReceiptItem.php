<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalDeliveryReceiptItem extends Model
{
    protected $fillable = [
        'internal_delivery_receipt_id',
        'internal_delivery_note_item_id',
        'quantity_received',
        'quantity_damaged',
        'quantity_short',
        'remarks',
    ];

    protected $casts = [
        'quantity_received' => 'decimal:3',
        'quantity_damaged' => 'decimal:3',
        'quantity_short' => 'decimal:3',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(InternalDeliveryReceipt::class, 'internal_delivery_receipt_id');
    }

    public function noteItem(): BelongsTo
    {
        return $this->belongsTo(InternalDeliveryNoteItem::class, 'internal_delivery_note_item_id');
    }
}
