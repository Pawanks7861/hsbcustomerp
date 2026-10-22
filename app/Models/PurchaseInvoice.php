<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseInvoice extends Model
{
    public const TAX_RATES = ['0', '5', '12', '18', '28'];

    public const STATUS_RECORDED = 'recorded';

    public const STATUS_PARTIAL = 'partially_paid';

    public const STATUS_PAID = 'paid';

    public const APPROVAL_DRAFT = 'draft';

    public const APPROVAL_SUBMITTED = 'submitted';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_REJECTED = 'rejected';

    protected $fillable = [
        'project_id',
        'purchase_order_id',
        'vendor_id',
        'invoice_number',
        'invoice_date',
        'invoice_amount',
        'tax_percentage',
        'tax_amount',
        'round_off',
        'total_amount',
        'due_date',
        'invoice_file_path',
        'remarks',
        'status',
        'approval_status',
        'submitted_at',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'created_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'invoice_amount' => 'decimal:2',
        'tax_percentage' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'round_off' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
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
        return $this->hasMany(PurchaseInvoiceItem::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VendorPayment::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(VendorAdvanceAdjustment::class);
    }

    public function hasSettlements(): bool
    {
        return $this->payments()->exists() || $this->adjustments()->exists();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function isApproved(): bool
    {
        return $this->approval_status === self::APPROVAL_APPROVED;
    }

    public function isEditable(): bool
    {
        return in_array($this->approval_status, [self::APPROVAL_DRAFT, self::APPROVAL_REJECTED], true)
            && ! $this->hasSettlements();
    }
}
