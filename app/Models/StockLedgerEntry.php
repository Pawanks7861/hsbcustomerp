<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockLedgerEntry extends Model
{
    public const RECEIPT = 'receipt';

    public const ISSUE = 'issue';

    public const ADJUSTMENT_IN = 'adjustment_in';

    public const ADJUSTMENT_OUT = 'adjustment_out';

    public const REVERSAL = 'reversal';

    public const TRANSFER_OUT = 'transfer_out';

    public const IN_TRANSIT = 'in_transit';

    public const TRANSFER_IN = 'transfer_in';

    public const DISCREPANCY = 'discrepancy';

    protected $fillable = [
        'project_id',
        'material_id',
        'location_id',
        'transaction_number',
        'transaction_date',
        'transaction_type',
        'source_type',
        'source_id',
        'source_item_id',
        'source_number',
        'stock_in',
        'stock_out',
        'balance',
        'unit_cost',
        'amount',
        'is_estimated',
        'posted_by',
        'remarks',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'stock_in' => 'decimal:3',
        'stock_out' => 'decimal:3',
        'balance' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'amount' => 'decimal:2',
        'is_estimated' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function costMovements(): HasMany
    {
        return $this->hasMany(StockCostMovement::class);
    }
}
