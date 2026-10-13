<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InternalDeliveryReceipt extends Model
{
    protected $fillable = [
        'internal_delivery_note_id',
        'received_at',
        'received_by',
        'remarks',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    public function note(): BelongsTo
    {
        return $this->belongsTo(InternalDeliveryNote::class, 'internal_delivery_note_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InternalDeliveryReceiptItem::class);
    }
}
