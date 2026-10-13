<?php

namespace App\Models;

use Database\Factories\BoqItemBudgetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoqItemBudget extends Model
{
    /** @use HasFactory<BoqItemBudgetFactory> */
    use HasFactory;

    public const DEFAULTS = [
        'Material',
        'Labour',
        'Equipment',
        'Miscellaneous',
    ];

    protected $fillable = [
        'boq_item_id',
        'name',
        'amount',
        'sort_order',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class, 'boq_item_id');
    }

    public function isDefault(): bool
    {
        return in_array($this->name, self::DEFAULTS, true);
    }
}
