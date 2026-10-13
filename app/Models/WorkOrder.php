<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const ISSUED = 'issued';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const SUPERSEDED = 'superseded';

    public const NOT_STARTED = 'not_started';

    /**
     * Statuses that reserve BOQ quantity. Draft, rejected, cancelled, and superseded do not.
     *
     * @var list<string>
     */
    public const ALLOCATING = [
        self::SUBMITTED,
        self::APPROVED,
        self::ISSUED,
        self::IN_PROGRESS,
        self::COMPLETED,
    ];

    protected $fillable = [
        'project_id',
        'vendor_id',
        'amended_from_id',
        'work_order_number',
        'work_order_date',
        'completion_date',
        'scope',
        'payment_terms',
        'terms_conditions',
        'remarks',
        'contractor_code',
        'contractor_name',
        'contractor_contact',
        'contractor_phone',
        'contractor_email',
        'contractor_address',
        'contractor_gst',
        'subtotal',
        'gst_percentage',
        'gst_amount',
        'grand_total',
        'status',
        'work_status',
        'is_quantity_exceeded',
        'submitted_at',
        'submitted_by',
        'approved_at',
        'approved_by',
        'approval_remarks',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'issued_at',
        'issued_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'created_by',
    ];

    protected $casts = [
        'work_order_date' => 'date',
        'completion_date' => 'date',
        'subtotal' => 'decimal:2',
        'gst_percentage' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'is_quantity_exceeded' => 'boolean',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'issued_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function amendedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'amended_from_id');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(self::class, 'amended_from_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function approvalLabel(): string
    {
        return match ($this->status) {
            self::DRAFT => 'Draft',
            self::SUBMITTED => 'Pending Approval',
            self::APPROVED, self::ISSUED, self::IN_PROGRESS, self::COMPLETED => 'Approved',
            self::REJECTED => 'Rejected',
            self::CANCELLED => 'Cancelled',
            self::SUPERSEDED => 'Superseded',
            default => (string) $this->status,
        };
    }

    public function workLabel(): string
    {
        return match ($this->work_status) {
            self::NOT_STARTED => 'Not Started',
            self::IN_PROGRESS => 'In Progress',
            self::COMPLETED => 'Completed',
            default => str_replace('_', ' ', (string) $this->work_status),
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::DRAFT => 'Draft',
            self::SUBMITTED => 'Pending Approval',
            self::APPROVED => 'Approved',
            self::ISSUED => 'Issued to Contractor',
            self::IN_PROGRESS => 'In Progress',
            self::COMPLETED => 'Completed',
            self::REJECTED => 'Rejected',
            self::CANCELLED => 'Cancelled',
            self::SUPERSEDED => 'Superseded',
            default => (string) $this->status,
        };
    }
}
