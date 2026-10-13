<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialReceipt extends Model
{
    public const KIND_STORE = 'store';

    public const KIND_GRN = 'grn';

    public const STATUS_LEGACY = 'legacy';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'project_id',
        'purchase_order_id',
        'vendor_id',
        'receipt_number',
        'document_kind',
        'status',
        'receipt_date',
        'received_by',
        'remarks',
        'challan_number',
        'challan_date',
        'vehicle_number',
        'inspection_notes',
        'created_by',
        'submitted_at',
        'submitted_by',
        'posted_at',
        'posted_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'cancelled_at',
        'cancelled_by',
    ];

    protected $casts = [
        'receipt_date' => 'date',
        'challan_date' => 'date',
        'submitted_at' => 'datetime',
        'posted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MaterialReceiptItem::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(MaterialReceiptImage::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function isGrn(): bool
    {
        return $this->document_kind === self::KIND_GRN;
    }

    public function typeLabel(): string
    {
        if ($this->isGrn()) {
            return 'Goods Receipt Note';
        }

        return $this->purchase_order_id ? 'PO Linked' : 'Manual';
    }
}
