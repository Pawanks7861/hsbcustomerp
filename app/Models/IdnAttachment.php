<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdnAttachment extends Model
{
    protected $fillable = [
        'internal_delivery_note_id',
        'internal_delivery_receipt_id',
        'path',
        'original_name',
    ];

    public function note(): BelongsTo
    {
        return $this->belongsTo(InternalDeliveryNote::class, 'internal_delivery_note_id');
    }
}
