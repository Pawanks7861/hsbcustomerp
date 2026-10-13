<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorRefund extends Model
{
    public const MODES = ['cash', 'bank_transfer', 'cheque', 'upi', 'other'];

    protected $fillable = [
        'vendor_id',
        'vendor_recovery_id',
        'project_id',
        'purchase_order_id',
        'purchase_invoice_id',
        'refund_date',
        'amount',
        'refund_mode',
        'reference_number',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'refund_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function recovery(): BelongsTo
    {
        return $this->belongsTo(VendorRecovery::class, 'vendor_recovery_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
