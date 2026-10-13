<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalDeliveryNoteItem extends Model
{
    protected $fillable = [
        'internal_delivery_note_id',
        'material_id',
        'quantity',
        'unit',
        'remarks',
        'quantity_accepted',
        'quantity_damaged',
        'quantity_short',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'quantity_accepted' => 'decimal:3',
        'quantity_damaged' => 'decimal:3',
        'quantity_short' => 'decimal:3',
    ];

    public function note(): BelongsTo
    {
        return $this->belongsTo(InternalDeliveryNote::class, 'internal_delivery_note_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
