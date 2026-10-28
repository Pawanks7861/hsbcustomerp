<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCostLayer extends Model
{
    protected $fillable = [
        'project_id',
        'material_id',
        'location_id',
        'remaining_qty',
        'unit_cost',
        'is_estimated',
        'source_type',
        'source_id',
        'source_item_id',
        'received_at',
    ];

    protected $casts = [
        'remaining_qty' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'is_estimated' => 'boolean',
        'received_at' => 'date',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockCostMovement::class);
    }
}
