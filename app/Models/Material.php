<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Material extends Model
{
    public const CLASS_CONSUMABLE = 'consumable';

    public const CLASS_ASSET = 'asset';

    protected $fillable = [
        'material_code',
        'name',
        'category',
        'description',
        'unit',
        'default_unit_price',
        'classification',
        'useful_life_years',
        'minimum_stock',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'default_unit_price' => 'decimal:2',
        'useful_life_years' => 'integer',
        'minimum_stock' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public function isAsset(): bool
    {
        return $this->classification === self::CLASS_ASSET;
    }

    public function isConsumable(): bool
    {
        return $this->classification !== self::CLASS_ASSET;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(StockLedgerEntry::class);
    }
}
