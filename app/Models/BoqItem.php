<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\BoqItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoqItem extends Model
{
    /** @use HasFactory<BoqItemFactory> */
    use HasFactory;

    protected $fillable = [
        'boq_id',
        'boq_revision_id',
        'parent_id',
        'sort_order',
        'item_number',
        'service_code',
        'is_section',
        'description',
        'unit',
        'quantity',
        'rate',
    ];

    protected $casts = [
        'is_section' => 'boolean',
        'quantity' => 'decimal:3',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (BoqItem $item): void {
            if ($item->boq_revision_id || ! $item->boq_id) {
                return;
            }

            $item->boq_revision_id = Boq::query()->whereKey($item->boq_id)->value('current_revision_id');
        });

        static::saving(function (BoqItem $item): void {
            $item->amount = $item->is_section
                ? null
                : Money::itemAmount($item->quantity, $item->rate);
        });
    }

    public function boq(): BelongsTo
    {
        return $this->belongsTo(Boq::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(BoqRevision::class, 'boq_revision_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(BoqItemBudget::class)->orderBy('sort_order')->orderBy('id');
    }

    public function purchaseRequests(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function isProcurable(): bool
    {
        return $this->quantity !== null && $this->rate !== null;
    }

    public function contributesToSubtotal(): bool
    {
        if ($this->is_section || $this->amount === null) {
            return false;
        }

        $hasChildren = $this->relationLoaded('children')
            ? $this->children->isNotEmpty()
            : $this->children()->exists();

        return ! $hasChildren;
    }

    public function budgetTotal(): string
    {
        $this->loadMissing('budgets');

        $total = '0.00';

        foreach ($this->budgets as $budget) {
            $total = Money::add($total, (string) $budget->amount);
        }

        return $total;
    }
}
