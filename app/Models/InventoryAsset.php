<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryAsset extends Model
{
    public const IN_STORE = 'in_store';

    public const IN_TRANSIT = 'in_transit';

    public const ASSIGNED = 'assigned';

    public const TRANSFERRED = 'transferred';

    protected $fillable = [
        'asset_code',
        'material_id',
        'parent_id',
        'quantity',
        'remaining_quantity',
        'unit_cost',
        'acquisition_cost',
        'useful_life_years',
        'project_id',
        'location_id',
        'status',
        'acquired_on',
        'source_type',
        'source_id',
        'source_item_id',
        'stock_cost_layer_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'remaining_quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'acquisition_cost' => 'decimal:2',
        'acquired_on' => 'date',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function originalId(): int
    {
        return (int) ($this->parent_id ?: $this->id);
    }

    public function isOriginalAcquisition(): bool
    {
        return $this->parent_id === null;
    }
}
