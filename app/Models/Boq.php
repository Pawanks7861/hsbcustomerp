<?php

namespace App\Models;

use App\Services\BoqRevisionService;
use App\Support\Money;
use Database\Factories\BoqFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Boq extends Model
{
    /** @use HasFactory<BoqFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'boq_number',
        'title',
        'current_revision_id',
        'gst_percentage',
    ];

    protected static function booted(): void
    {
        static::creating(function (Boq $boq): void {
            if (! $boq->boq_number && $boq->project_id) {
                $boq->boq_number = app(BoqRevisionService::class)->nextNumber((int) $boq->project_id);
            }
        });

        static::created(function (Boq $boq): void {
            app(BoqRevisionService::class)->ensureOriginal($boq);
        });
    }

    protected $casts = [
        'gst_percentage' => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BoqItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function currentItems(): HasMany
    {
        return $this->hasMany(BoqItem::class, 'boq_revision_id', 'current_revision_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(BoqRevision::class)->orderBy('revision_number');
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(BoqRevision::class, 'current_revision_id');
    }

    public function procurementRevisionId(): ?int
    {
        $current = $this->currentRevision;

        if ($current && $current->isFinalized()) {
            return $current->id;
        }

        $finalizedId = $this->revisions()
            ->where('status', BoqRevision::FINALIZED)
            ->orderByDesc('revision_number')
            ->value('id');

        return $finalizedId ?: $this->current_revision_id;
    }

    public function purchaseRequests(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function subtotal(): string
    {
        $this->loadMissing('currentItems.children');

        $total = '0.00';

        foreach ($this->currentItems as $item) {
            if ($item->contributesToSubtotal()) {
                $total = Money::add($total, (string) $item->amount);
            }
        }

        return $total;
    }

    public function gstAmount(): string
    {
        return Money::percentage($this->subtotal(), (string) $this->gst_percentage);
    }

    public function grandTotal(): string
    {
        return Money::add($this->subtotal(), $this->gstAmount());
    }

    public function budgetTotal(): string
    {
        $this->loadMissing('currentItems.budgets');

        $total = '0.00';

        foreach ($this->currentItems as $item) {
            $total = Money::add($total, $item->budgetTotal());
        }

        return $total;
    }
}
