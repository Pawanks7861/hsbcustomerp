<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialReceiptItem extends Model
{
    protected $fillable = [
        'material_receipt_id',
        'purchase_order_item_id',
        'material_id',
        'description',
        'unit',
        'quantity_received',
        'quantity_accepted',
        'quantity_rejected',
        'quantity_previously_received',
        'remarks',
    ];

    protected $casts = [
        'quantity_received' => 'decimal:3',
        'quantity_accepted' => 'decimal:3',
        'quantity_rejected' => 'decimal:3',
        'quantity_previously_received' => 'decimal:3',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(MaterialReceipt::class, 'material_receipt_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
