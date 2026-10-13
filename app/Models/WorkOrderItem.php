<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderItem extends Model
{
    protected $fillable = [
        'work_order_id',
        'boq_item_id',
        'boq_revision_id',
        'sort_order',
        'boq_number',
        'revision_number',
        'item_number',
        'service_code',
        'description',
        'unit',
        'boq_quantity',
        'previously_allocated',
        'quantity',
        'rate',
        'amount',
        'excess_quantity',
        'is_exceeded',
        'executed_quantity',
        'execution_status',
    ];

    protected $casts = [
        'boq_quantity' => 'decimal:3',
        'previously_allocated' => 'decimal:3',
        'quantity' => 'decimal:3',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'excess_quantity' => 'decimal:3',
        'is_exceeded' => 'boolean',
        'executed_quantity' => 'decimal:3',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(BoqRevision::class, 'boq_revision_id');
    }
}
