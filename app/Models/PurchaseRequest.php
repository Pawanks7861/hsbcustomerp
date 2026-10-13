<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequest extends Model
{
    use HasDocumentStatus;

    protected $fillable = [
        'project_id',
        'boq_id',
        'boq_item_id',
        'pr_number',
        'request_date',
        'required_date',
        'requested_by',
        'description',
        'status',
        'submitted_at',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'requested_boq_quantity',
        'is_boq_quantity_exceeded',
        'boq_quantity_at_submission',
        'previous_requested_quantity_at_submission',
        'excess_quantity_at_submission',
        'over_quantity_approved_by',
        'over_quantity_approved_at',
        'over_quantity_approval_note',
    ];

    protected $casts = [
        'request_date' => 'date',
        'required_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'requested_boq_quantity' => 'decimal:3',
        'is_boq_quantity_exceeded' => 'boolean',
        'boq_quantity_at_submission' => 'decimal:3',
        'previous_requested_quantity_at_submission' => 'decimal:3',
        'excess_quantity_at_submission' => 'decimal:3',
        'over_quantity_approved_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function boq(): BelongsTo
    {
        return $this->belongsTo(Boq::class);
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class)->orderBy('id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function overQuantityApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'over_quantity_approved_by');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function estimatedTotal(): string
    {
        $this->loadMissing('items');

        $total = '0.00';

        foreach ($this->items as $item) {
            if ($item->estimated_amount !== null) {
                $total = Money::add($total, (string) $item->estimated_amount);
            }
        }

        return $total;
    }
}
