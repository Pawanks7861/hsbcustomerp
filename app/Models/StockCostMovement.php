<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCostMovement extends Model
{
    protected $fillable = [
        'stock_ledger_entry_id',
        'stock_cost_layer_id',
        'inventory_asset_id',
        'quantity',
        'unit_cost',
        'amount',
        'is_estimated',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'amount' => 'decimal:2',
        'is_estimated' => 'boolean',
    ];

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(StockLedgerEntry::class, 'stock_ledger_entry_id');
    }

    public function layer(): BelongsTo
    {
        return $this->belongsTo(StockCostLayer::class, 'stock_cost_layer_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InventoryAsset::class, 'inventory_asset_id');
    }
}
