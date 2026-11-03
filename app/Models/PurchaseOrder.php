<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasDocumentStatus;

    protected $fillable = [
        'project_id',
        'boq_id',
        'boq_item_id',
        'purchase_request_id',
        'vendor_id',
        'vendor_name_snapshot',
        'vendor_address_snapshot',
        'vendor_city_snapshot',
        'vendor_state_snapshot',
        'vendor_pincode_snapshot',
        'vendor_phone_snapshot',
        'vendor_email_snapshot',
        'vendor_gst_snapshot',
        'po_number',
        'subject',
        'po_date',
        'supplier_name',
        'supplier_address',
        'supplier_phone',
        'supplier_email',
        'gst_number',
        'delivery_date',
        'delivery_address',
        'place_of_supply',
        'subtotal',
        'discount_total',
        'taxable_value',
        'gst_percentage',
        'cgst_percentage',
        'sgst_percentage',
        'gst_amount',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'freight',
        'round_off',
        'grand_total',
        'terms_conditions',
        'remarks',
        'status',
        'created_by',
        'submitted_at',
        'approved_at',
        'approved_by',
        'first_approved_by',
        'first_approved_at',
        'second_approved_by',
        'second_approved_at',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
    ];

    protected $casts = [
        'po_date' => 'date',
        'delivery_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'taxable_value' => 'decimal:2',
        'gst_percentage' => 'decimal:2',
        'cgst_percentage' => 'decimal:2',
        'sgst_percentage' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'freight' => 'decimal:2',
        'round_off' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'first_approved_at' => 'datetime',
        'second_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
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

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('id');
    }

    public function rateAmendments(): HasMany
    {
        return $this->hasMany(PurchaseOrderRateAmendment::class)->orderByDesc('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function firstApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_approved_by');
    }

    public function secondApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_approved_by');
    }

    public function isFullyApproved(): bool
    {
        return $this->status === \App\Support\DocumentStatus::APPROVED
            && $this->first_approved_by
            && $this->second_approved_by;
    }

    public function printable(): bool
    {
        return $this->status === \App\Support\DocumentStatus::APPROVED;
    }
}
