<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorPayment extends Model
{
    public const TYPE_ADVANCE = 'advance';

    public const TYPE_INVOICE = 'invoice_payment';

    public const TYPE_OTHER = 'other';

    public const MODES = ['cash', 'bank_transfer', 'cheque', 'upi', 'card', 'other'];

    protected $fillable = [
        'vendor_id',
        'project_id',
        'purchase_order_id',
        'purchase_invoice_id',
        'payment_date',
        'payment_type',
        'payment_mode',
        'amount',
        'reference_number',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(VendorAdvanceAdjustment::class);
    }

    public function recoveries(): HasMany
    {
        return $this->hasMany(VendorRecovery::class);
    }

    public function typeLabel(): string
    {
        return match ($this->payment_type) {
            self::TYPE_ADVANCE => 'Advance Payment',
            self::TYPE_INVOICE => 'Invoice Payment',
            default => 'Other Payment',
        };
    }
}
